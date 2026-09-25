package bridge

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/gorilla/websocket"
	"io"
	"net/http"
	"net/url"
	"sync"
	"time"
)

type Connector struct {
	Endpoint   string
	Backups    []string
	Key        string
	Panel      string
	PanelKey   string
	WebSocket  string
	AllowLocal bool
	Client     *http.Client
}

func (c *Connector) httpClient() *http.Client {
	if c.Client != nil {
		return c.Client
	}
	return &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
}
func (c *Connector) Run(ctx context.Context) error {
	if _, e := PublicURL(c.Endpoint, c.AllowLocal); e != nil {
		return e
	}
	if len(c.Key) < 32 || len(c.PanelKey) < 32 {
		return fmt.Errorf("connector keys must have at least 32 characters")
	}
	local, e := url.Parse(c.Panel)
	if e != nil || local.Scheme != "http" || local.Hostname() != "127.0.0.1" {
		return fmt.Errorf("panel endpoint must be loopback HTTP")
	}
	ws, e := url.Parse(c.WebSocket)
	if e != nil || ws.Scheme != "ws" || ws.Hostname() != "127.0.0.1" {
		return fmt.Errorf("panel websocket must be loopback WS")
	}
	known := append([]string{c.Endpoint}, c.Backups...)
	desired := c.Endpoint
	for ctx.Err() == nil {
		check, stop := context.WithTimeout(ctx, 5*time.Second)
		status, b, err := c.post(check, "/api/v2/probe/endpoint", map[string]string{})
		stop()
		if err == nil && status == 200 {
			var v struct {
				Endpoint string
				Backups  []string
			}
			if json.Unmarshal(b, &v) == nil {
				if _, e := PublicURL(v.Endpoint, c.AllowLocal); e == nil {
					desired = v.Endpoint
					known = append(append([]string{desired}, v.Backups...), known...)
				}
			}
		}
		candidates := []string{}
		seen := map[string]bool{}
		for _, s := range known {
			if _, e := PublicURL(s, c.AllowLocal); e != nil || seen[s] {
				continue
			}
			seen[s] = true
			candidates = append(candidates, s)
			if len(candidates) == 9 {
				break
			}
		}
		known = candidates
		var conn *websocket.Conn
		for _, endpoint := range candidates {
			u, _ := PublicURL(endpoint, c.AllowLocal)
			if u.Scheme == "https" {
				u.Scheme = "wss"
			} else {
				u.Scheme = "ws"
			}
			u.Path = Prefix + "/control/connect"
			attempt, cancel := context.WithTimeout(ctx, 8*time.Second)
			conn, _, err = websocket.DefaultDialer.DialContext(attempt, u.String(), http.Header{"Authorization": []string{"Bearer " + c.Key}})
			cancel()
			if err == nil {
				break
			}
			conn = nil
		}
		if conn != nil {
			sessionCtx, stop := context.WithCancel(ctx)
			initialDesired := desired
			go func() {
				ticker := time.NewTicker(10 * time.Second)
				defer ticker.Stop()
				for {
					select {
					case <-sessionCtx.Done():
						return
					case <-ticker.C:
						q, done := context.WithTimeout(sessionCtx, 5*time.Second)
						status, b, e := c.post(q, "/api/v2/probe/endpoint", map[string]string{})
						done()
						var v struct{ Endpoint string }
						if e == nil && status == 200 && json.Unmarshal(b, &v) == nil && v.Endpoint != initialDesired {
							if _, err := PublicURL(v.Endpoint, c.AllowLocal); err == nil {
								stop()
								return
							}
						}
					}
				}
			}()
			c.session(sessionCtx, NewPeer(conn))
			stop()
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(2 * time.Second):
		}
	}
	return nil
}
func (c *Connector) post(ctx context.Context, path string, input any) (int, []byte, error) {
	b, _ := json.Marshal(input)
	r, e := http.NewRequestWithContext(ctx, "POST", c.Panel+path, bytes.NewReader(b))
	if e != nil {
		return 0, nil, e
	}
	r.Header.Set("Authorization", "Bearer "+c.PanelKey)
	r.Header.Set("Content-Type", "application/json")
	resp, e := c.httpClient().Do(r)
	if e != nil {
		return 0, nil, e
	}
	defer resp.Body.Close()
	body, e := io.ReadAll(io.LimitReader(resp.Body, MaxBody+1))
	if len(body) > MaxBody {
		return 0, nil, fmt.Errorf("panel response too large")
	}
	return resp.StatusCode, body, e
}
func (c *Connector) session(parent context.Context, p *Peer) {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()
	defer p.Close()
	var mu sync.Mutex
	streams := map[string]*websocket.Conn{}
	pending := map[string]context.CancelFunc{}
	slots := make(chan struct{}, 64)
	defer func() {
		mu.Lock()
		defer mu.Unlock()
		cancel()
		for _, stop := range pending {
			stop()
		}
		for _, w := range streams {
			w.Close()
		}
	}()
	p.Serve(ctx, func(f Frame) {
		switch f.Kind {
		case "http", "ws.open":
			select {
			case slots <- struct{}{}:
			default:
				p.Send(Frame{ID: f.ID, Kind: "response", Status: 503})
				return
			}
			reqctx, done := context.WithTimeout(ctx, 22*time.Second)
			if f.Kind == "ws.open" {
				mu.Lock()
				pending[f.ID] = done
				mu.Unlock()
			}
			go func() {
				defer func() { <-slots; done(); mu.Lock(); delete(pending, f.ID); mu.Unlock() }()
				if f.Kind == "http" {
					if !AllowedPath(f.Path) {
						p.Send(Frame{ID: f.ID, Kind: "response", Status: 403})
						return
					}
					status, b, e := c.post(reqctx, "/api/v2/probe/dispatch", f)
					if e != nil {
						status = 503
						b = []byte("{}")
					}
					var reply Frame
					if status != 200 || json.Unmarshal(b, &reply) != nil {
						reply = Frame{Status: 503}
						switch status {
						case 400, 401, 403, 404, 409, 413, 422:
							reply = Frame{Status: status, Body: []byte("{\"error\":\"probe request rejected\"}")}
						}
					}
					reply.ID = f.ID
					reply.Kind = "response"
					p.Send(reply)
					return
				}
				status, b, e := c.post(reqctx, "/api/v2/probe/credential", map[string]string{"device": f.Device})
				var cred struct {
					MachineID int    `json:"machine_id"`
					Token     string `json:"token"`
				}
				if e != nil || status != 200 || json.Unmarshal(b, &cred) != nil || cred.MachineID <= 0 {
					p.Send(Frame{ID: f.ID, Kind: "ws.close"})
					return
				}
				u, _ := url.Parse(c.WebSocket)
				q := f.Query
				if q == nil {
					q = url.Values{}
				}
				q.Set("machine_id", fmt.Sprint(cred.MachineID))
				q.Set("token", cred.Token)
				q.Del("node_id")
				u.RawQuery = q.Encode()
				w, _, e := websocket.DefaultDialer.DialContext(reqctx, u.String(), nil)
				if e != nil {
					p.Send(Frame{ID: f.ID, Kind: "ws.close"})
					return
				}
				mu.Lock()
				if reqctx.Err() != nil || ctx.Err() != nil {
					mu.Unlock()
					w.Close()
					return
				}
				streams[f.ID] = w
				mu.Unlock()
				w.SetReadLimit(MaxBody)
				// Reading is separate from the request slot: a long lived stream must not exhaust HTTP capacity.
				go func() {
					defer func() {
						w.Close()
						mu.Lock()
						delete(streams, f.ID)
						mu.Unlock()
						p.Send(Frame{ID: f.ID, Kind: "ws.close"})
					}()
					for {
						_, b, e := w.ReadMessage()
						if e != nil {
							return
						}
						if e = p.Send(Frame{ID: f.ID, Kind: "ws.data", Body: b}); e != nil {
							return
						}
					}
				}()
			}()
		case "ws.data":
			mu.Lock()
			w := streams[f.ID]
			mu.Unlock()
			if w != nil {
				w.SetWriteDeadline(time.Now().Add(10 * time.Second))
				if w.WriteMessage(websocket.TextMessage, f.Body) != nil {
					w.Close()
				}
			}
		case "ws.close":
			mu.Lock()
			if stop := pending[f.ID]; stop != nil {
				stop()
			}
			w := streams[f.ID]
			mu.Unlock()
			if w != nil {
				w.Close()
			}
		}
	})
}
