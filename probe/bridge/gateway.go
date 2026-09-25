package bridge

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

type Gateway struct {
	Registry   *Registry
	Public     string
	ControlKey string
	Artifacts  string
	Provision  func(Device) (uint64, error)
	controlMu  sync.Mutex
	mu         sync.Mutex
	peer       *Peer
	sessions   map[string]map[*websocket.Conn]bool
}

var UUIDPattern = regexp.MustCompile(`^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$`)
var VersionPattern = regexp.MustCompile(`^v[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$`)

func (g *Gateway) Handler(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, Prefix+"/") {
			next.ServeHTTP(w, r)
			return
		}
		g.serve(w, r)
	})
}
func (g *Gateway) online() *Peer { g.mu.Lock(); defer g.mu.Unlock(); return g.peer }
func (g *Gateway) revoke(id string) {
	g.mu.Lock()
	defer g.mu.Unlock()
	for c := range g.sessions[id] {
		c.Close()
	}
}
func (g *Gateway) serve(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	p := strings.TrimPrefix(r.URL.Path, Prefix)
	if p == "/control/connect" || strings.HasPrefix(p, "/control/") {
		if !equal(g.ControlKey, bearer(r)) || len(g.ControlKey) < 32 {
			http.Error(w, "unauthorized", 401)
			return
		}
		switch p {
		case "/control/connect":
			g.connect(w, r)
			return
		case "/control/status":
			writeJSON(w, 200, map[string]any{"connected": g.online() != nil, "public_url": g.Public, "gateway_id": digest(g.ControlKey)})
			return
		case "/control/device":
			g.controlMu.Lock()
			defer g.controlMu.Unlock()
			if r.Method != "POST" {
				http.Error(w, "method", 405)
				return
			}
			var v struct {
				UUID    string `json:"uuid"`
				Name    string `json:"name"`
				Enabled bool   `json:"enabled"`
				Code    string `json:"code"`
			}
			if json.NewDecoder(http.MaxBytesReader(w, r.Body, 8192)).Decode(&v) != nil || !UUIDPattern.MatchString(v.UUID) || len(v.Name) > 255 {
				http.Error(w, "invalid device", 422)
				return
			}
			d, ok := g.Registry.Get(v.UUID)
			if !ok {
				d = Device{UUID: v.UUID}
			}
			d.Name = v.Name
			d.Enabled = v.Enabled
			if v.Code != "" {
				if len(v.Code) < 32 {
					http.Error(w, "invalid code", 422)
					return
				}
				d.EnrollHash = digest(v.Code)
				d.EnrollExpires = time.Now().Add(15 * time.Minute).Unix()
				// Existing agent key remains valid until enrollment succeeds.
			}
			if g.Provision != nil {
				sid, e := g.Provision(d)
				if e != nil {
					http.Error(w, "provision failed", 503)
					return
				}
				d.ServerID = sid
			}
			if e := g.Registry.Put(d); e != nil {
				http.Error(w, "storage unavailable", 503)
				return
			}
			if !d.Enabled {
				g.revoke(d.UUID)
			}
			writeJSON(w, 200, map[string]any{"uuid": d.UUID, "server_id": d.ServerID})
			return
		}
		http.NotFound(w, r)
		return
	}
	if p == "/enroll" {
		g.controlMu.Lock()
		defer g.controlMu.Unlock()
		if r.Method != "POST" {
			http.Error(w, "method", 405)
			return
		}
		var v struct {
			UUID   string `json:"uuid"`
			Code   string `json:"code"`
			Secret string `json:"secret"`
		}
		if json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&v) != nil {
			http.Error(w, "invalid enrollment", 422)
			return
		}
		d, e := g.Registry.Enroll(v.UUID, v.Code, v.Secret)
		if e != nil {
			http.Error(w, "invalid or expired enrollment", 403)
			return
		}
		g.revoke(d.UUID)
		writeJSON(w, 200, map[string]any{"uuid": d.UUID, "endpoint": g.Public})
		return
	}
	if p == "/install.sh" {
		http.ServeFile(w, r, filepath.Join(g.Artifacts, "install.sh"))
		return
	}
	if strings.HasPrefix(p, "/artifacts/") {
		g.artifact(w, r, strings.TrimPrefix(p, "/artifacts/"))
		return
	}
	id := r.Header.Get("X-Probe-Device")
	secret := bearer(r)
	if id == "" {
		id = r.URL.Query().Get("device")
		secret = r.URL.Query().Get("token")
	}
	d, ok := g.Registry.Authorize(id, secret)
	if !ok {
		http.Error(w, "unauthorized", 403)
		return
	}
	if p == "/check" {
		if g.online() == nil {
			http.Error(w, "connector unavailable", 503)
			return
		}
		writeJSON(w, 200, map[string]any{"device": d.UUID})
		return
	}
	if p == "/node/ws" {
		g.nodeWS(w, r, d)
		return
	}
	api := strings.TrimPrefix(p, "/node")
	if !AllowedPath(api) || (r.Method != "GET" && r.Method != "POST") {
		http.NotFound(w, r)
		return
	}
	data, e := io.ReadAll(http.MaxBytesReader(w, r.Body, MaxBody))
	if e != nil {
		http.Error(w, "body too large", 413)
		return
	}
	q := r.URL.Query()
	q.Del("token")
	q.Del("machine_id")
	q.Del("device")
	headers := map[string]string{"If-None-Match": r.Header.Get("If-None-Match"), "Content-Type": "application/json", "X-Probe-Request-ID": r.Header.Get("X-Probe-Request-ID"), "X-DBoard-User-Routes": r.Header.Get("X-DBoard-User-Routes"), "X-DBoard-Front-Gate": r.Header.Get("X-DBoard-Front-Gate")}
	f := Frame{ID: ID(), Kind: "http", Device: id, Method: r.Method, Path: api, Query: q, Headers: headers, Body: data}
	peer := g.online()
	if peer == nil {
		http.Error(w, "connector unavailable", 503)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
	defer cancel()
	resp, e := peer.Call(ctx, f)
	if e != nil {
		http.Error(w, "connector unavailable", 503)
		return
	}
	if api == "/api/v2/server/handshake" && resp.Status == 200 {
		u, _ := url.Parse(g.Public)
		if u.Scheme == "https" {
			u.Scheme = "wss"
		} else {
			u.Scheme = "ws"
		}
		u.Path = Prefix + "/node/ws"
		q := u.Query()
		q.Set("device", id)
		u.RawQuery = q.Encode()
		resp.Body, _ = json.Marshal(map[string]any{"websocket": map[string]any{"enabled": true, "ws_url": u.String()}})
	}
	for k, v := range resp.Headers {
		if k == "ETag" || k == "Content-Type" {
			w.Header().Set(k, v)
		}
	}
	if resp.Status < 200 || resp.Status > 599 {
		http.Error(w, "invalid connector reply", 502)
		return
	}
	if resp.Status >= 500 {
		http.Error(w, "upstream unavailable", 503)
		return
	}
	w.WriteHeader(resp.Status)
	w.Write(resp.Body)
}
func (g *Gateway) connect(w http.ResponseWriter, r *http.Request) {
	up := websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return r.Header.Get("Origin") == "" }}
	c, e := up.Upgrade(w, r, nil)
	if e != nil {
		return
	}
	p := NewPeer(c)
	g.mu.Lock()
	old := g.peer
	g.peer = p
	g.mu.Unlock()
	if old != nil {
		old.Close()
	}
	defer func() {
		g.mu.Lock()
		if g.peer == p {
			g.peer = nil
		}
		g.mu.Unlock()
	}()
	p.Serve(r.Context(), nil)
}
func (g *Gateway) nodeWS(w http.ResponseWriter, r *http.Request, d Device) {
	p := g.online()
	if p == nil {
		http.Error(w, "connector unavailable", 503)
		return
	}
	up := websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return r.Header.Get("Origin") == "" }}
	c, e := up.Upgrade(w, r, nil)
	if e != nil {
		return
	}
	defer c.Close()
	c.SetReadLimit(MaxBody)
	g.mu.Lock()
	if g.sessions == nil {
		g.sessions = map[string]map[*websocket.Conn]bool{}
	}
	if g.sessions[d.UUID] == nil {
		g.sessions[d.UUID] = map[*websocket.Conn]bool{}
	}
	g.sessions[d.UUID][c] = true
	g.mu.Unlock()
	defer func() { g.mu.Lock(); delete(g.sessions[d.UUID], c); g.mu.Unlock() }()
	sid := ID()
	ch, remove := p.Stream(sid)
	defer remove()
	defer p.Send(Frame{ID: sid, Kind: "ws.close"})
	q := r.URL.Query()
	q.Del("token")
	q.Del("machine_id")
	q.Del("device")
	if e = p.Send(Frame{ID: sid, Kind: "ws.open", Device: d.UUID, Query: q}); e != nil {
		return
	}
	done := make(chan struct{})
	defer close(done)
	go func() {
		defer c.Close()
		for {
			select {
			case f := <-ch:
				if f.Kind == "ws.close" {
					return
				}
				c.SetWriteDeadline(time.Now().Add(15 * time.Second))
				if e := c.WriteMessage(websocket.TextMessage, f.Body); e != nil {
					return
				}
			case <-p.done:
				return
			case <-done:
				return
			}
		}
	}()
	for {
		_, b, e := c.ReadMessage()
		if e != nil {
			return
		}
		if _, ok := g.Registry.Authorize(d.UUID, func() string {
			if r.Header.Get("X-Probe-Device") != "" {
				return bearer(r)
			}
			return r.URL.Query().Get("token")
		}()); !ok {
			return
		}
		if e = p.Send(Frame{ID: sid, Kind: "ws.data", Device: d.UUID, Body: b}); e != nil {
			return
		}
	}
}
func (g *Gateway) artifact(w http.ResponseWriter, r *http.Request, path string) {
	parts := strings.Split(path, "/")
	if len(parts) != 2 || !VersionPattern.MatchString(parts[0]) {
		http.NotFound(w, r)
		return
	}
	switch parts[1] {
	case "nezha-agent-linux-amd64", "nezha-agent-linux-arm64", "SHA256SUMS":
	default:
		http.NotFound(w, r)
		return
	}
	f := filepath.Join(g.Artifacts, parts[0], parts[1])
	if _, e := os.Stat(f); e != nil {
		http.NotFound(w, r)
		return
	}
	http.ServeFile(w, r, f)
}
