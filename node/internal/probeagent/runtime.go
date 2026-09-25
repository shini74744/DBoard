package probeagent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/shini74744/DBoard/probe/bridge"
)

type Runtime struct {
	mu               sync.RWMutex
	cfg              Config
	path             string
	transport        *http.Transport
	sockets          map[*websocket.Conn]bool
	OnEndpoint       func(Config)
	ValidateEndpoint func(context.Context, Config) error
	MonitorHealthy   func() bool
	migration        sync.Mutex
}

func New(c Config, path string) (*Runtime, error) {
	if e := c.Validate(); e != nil {
		return nil, e
	}
	if e := os.MkdirAll(filepath.Join(c.DataDir, "outbox"), 0700); e != nil {
		return nil, e
	}
	return &Runtime{cfg: c, path: path, transport: &http.Transport{MaxIdleConns: 32, MaxIdleConnsPerHost: 16, IdleConnTimeout: 90 * time.Second}, sockets: map[*websocket.Conn]bool{}}, nil
}
func (r *Runtime) Config() Config { r.mu.RLock(); defer r.mu.RUnlock(); return r.cfg }
func (r *Runtime) direct(ctx context.Context, endpoint, path, method string, body []byte, headers http.Header, query url.Values) (*http.Response, error) {
	c := r.Config()
	u, e := bridge.PublicURL(endpoint, c.AllowLocal)
	if e != nil {
		return nil, e
	}
	u.Path = path
	u.RawQuery = query.Encode()
	req, e := http.NewRequestWithContext(ctx, method, u.String(), bytes.NewReader(body))
	if e != nil {
		return nil, e
	}
	req.Header = headers.Clone()
	if req.Header == nil {
		req.Header = http.Header{}
	}
	req.Header.Set("Authorization", "Bearer "+c.Secret)
	req.Header.Set("X-Probe-Device", c.UUID)
	return r.transport.RoundTrip(req)
}
func (r *Runtime) RoundTrip(req *http.Request) (*http.Response, error) {
	path := req.URL.Path
	if !strings.HasPrefix(path, bridge.Prefix+"/node/") || !bridge.AllowedPath(strings.TrimPrefix(path, bridge.Prefix+"/node")) {
		return nil, errors.New("request outside probe API")
	}
	var b []byte
	var e error
	if req.Body != nil {
		b, e = io.ReadAll(io.LimitReader(req.Body, bridge.MaxBody+1))
		req.Body.Close()
		if e != nil {
			return nil, e
		}
		if len(b) > bridge.MaxBody {
			return nil, errors.New("report too large")
		}
	}
	q := req.URL.Query()
	q.Del("token")
	q.Del("machine_id")
	if path == bridge.Prefix+"/node/api/v2/server/report" {
		id := bridge.ID()
		f := bridge.Frame{ID: id, Path: path, Method: req.Method, Query: q, Body: b}
		data, _ := json.Marshal(f)
		c := r.Config()
		entries, _ := os.ReadDir(filepath.Join(c.DataDir, "outbox"))
		if len(entries) >= 10000 {
			return nil, errors.New("probe report outbox full")
		}
		if e = atomicWrite(filepath.Join(c.DataDir, "outbox", fmt.Sprintf("%020d-%s.json", time.Now().UnixNano(), id)), data, 0600); e != nil {
			return nil, e
		}
		return &http.Response{StatusCode: 200, Header: http.Header{"Content-Type": []string{"application/json"}}, Body: io.NopCloser(strings.NewReader(`{"data":true}`)), Request: req}, nil
	}
	return r.direct(req.Context(), r.Config().Endpoint, path, req.Method, b, req.Header, q)
}
func (r *Runtime) DialWS(ctx context.Context, raw string) (*websocket.Conn, *http.Response, error) {
	c := r.Config()
	u, e := url.Parse(raw)
	if e != nil {
		return nil, nil, e
	}
	if u.Path != bridge.Prefix+"/node/ws" {
		return nil, nil, errors.New("websocket outside probe API")
	}
	target, _ := url.Parse(c.Endpoint)
	if target.Scheme == "https" {
		target.Scheme = "wss"
	} else {
		target.Scheme = "ws"
	}
	target.Path = u.Path
	q := u.Query()
	q.Del("token")
	q.Del("machine_id")
	q.Set("device", c.UUID)
	q.Set("probe_endpoint", c.Endpoint)
	target.RawQuery = q.Encode()
	d := websocket.Dialer{HandshakeTimeout: 15 * time.Second}
	conn, resp, e := d.DialContext(ctx, target.String(), http.Header{"Authorization": []string{"Bearer " + c.Secret}, "X-Probe-Device": []string{c.UUID}})
	if e == nil {
		r.mu.Lock()
		r.sockets[conn] = true
		r.mu.Unlock()
	}
	return conn, resp, e
}
func (r *Runtime) ForgetWS(c *websocket.Conn) { r.mu.Lock(); delete(r.sockets, c); r.mu.Unlock() }
func (r *Runtime) Run(ctx context.Context) {
	t := time.NewTicker(2 * time.Second)
	defer t.Stop()
	failures := 0
	for {
		e := r.flush(ctx)
		if e != nil {
			failures++
		} else {
			failures = 0
		}
		if failures >= 3 {
			r.tryBackups(ctx)
			failures = 0
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
func (r *Runtime) flush(ctx context.Context) error {
	c := r.Config()
	dir := filepath.Join(c.DataDir, "outbox")
	entries, e := os.ReadDir(dir)
	if e != nil {
		return e
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		p := filepath.Join(dir, entry.Name())
		data, e := os.ReadFile(p)
		if e != nil {
			return e
		}
		var f bridge.Frame
		if e = json.Unmarshal(data, &f); e != nil {
			return e
		}
		reqctx, cancel := context.WithTimeout(ctx, 28*time.Second)
		resp, e := r.direct(reqctx, r.Config().Endpoint, f.Path, f.Method, f.Body, http.Header{"Content-Type": []string{"application/json"}, "X-Probe-Request-ID": []string{f.ID}}, f.Query)
		if e == nil {
			io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
			resp.Body.Close()
			if resp.StatusCode != 200 {
				switch resp.StatusCode {
				case 400, 404, 409, 413, 422:
					// A deleted node or permanently invalid batch must not block
					// reports for every other node on this server. Retain it for review.
					quarantine := filepath.Join(c.DataDir, "quarantine")
					if er := os.MkdirAll(quarantine, 0700); er != nil {
						cancel()
						return er
					}
					if er := os.Rename(p, filepath.Join(quarantine, entry.Name())); er != nil {
						cancel()
						return er
					}
					cancel()
					continue
				}
				e = fmt.Errorf("probe report status %d", resp.StatusCode)
			}
		}
		cancel()
		if e != nil {
			return e
		}
		if e = os.Remove(p); e != nil {
			return e
		}
	}
	return nil
}
func (r *Runtime) Check(ctx context.Context, endpoint string) error {
	resp, e := r.direct(ctx, endpoint, bridge.Prefix+"/check", "GET", nil, nil, nil)
	if e != nil {
		return e
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("probe authentication check failed: %d", resp.StatusCode)
	}
	return nil
}
func (r *Runtime) Switch(ctx context.Context, endpoint string, backups []string) error {
	r.migration.Lock()
	defer r.migration.Unlock()
	old := r.Config()
	next := old
	next.Endpoint = endpoint
	next.Backups = backups
	if e := next.Validate(); e != nil {
		return e
	}
	if e := r.Check(ctx, endpoint); e != nil {
		return e
	}
	if r.ValidateEndpoint != nil {
		if e := r.ValidateEndpoint(ctx, next); e != nil {
			return e
		}
	}
	if e := next.Save(r.path); e != nil {
		return e
	}
	r.mu.Lock()
	r.cfg = next
	for c := range r.sockets {
		c.Close()
	}
	r.sockets = map[*websocket.Conn]bool{}
	r.mu.Unlock()
	r.transport.CloseIdleConnections()
	if r.OnEndpoint != nil {
		r.OnEndpoint(next)
	}
	return nil
}
func (r *Runtime) tryBackups(ctx context.Context) {
	c := r.Config()
	for _, s := range c.Backups {
		if s == c.Endpoint {
			continue
		}
		backups := []string{c.Endpoint}
		for _, b := range c.Backups {
			if b != s {
				backups = append(backups, b)
			}
		}
		checkctx, cancel := context.WithTimeout(ctx, 8*time.Second)
		e := r.Switch(checkctx, s, backups)
		cancel()
		if e == nil {
			return
		}
	}
}

// Watch detects a dead primary even when an idle node has no traffic to report.
func (r *Runtime) Watch(ctx context.Context) {
	t := time.NewTicker(20 * time.Second)
	defer t.Stop()
	bad := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			q, cancel := context.WithTimeout(ctx, 8*time.Second)
			e := r.Check(q, r.Config().Endpoint)
			if e == nil && r.MonitorHealthy != nil && !r.MonitorHealthy() {
				e = errors.New("monitoring channel unavailable")
			}
			cancel()
			if e != nil {
				bad++
			} else {
				bad = 0
			}
			if bad >= 3 {
				r.tryBackups(ctx)
				bad = 0
			}
		}
	}
}
func (r *Runtime) Event(kind string, data json.RawMessage, reply func(string, json.RawMessage)) bool {
	if kind != "probe.endpoint" {
		return false
	}
	go func() {
		var v struct {
			RequestID string   `json:"request_id"`
			Endpoint  string   `json:"endpoint"`
			Backups   []string `json:"backups"`
		}
		if json.Unmarshal(data, &v) != nil {
			return
		}
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		e := r.Switch(ctx, v.Endpoint, v.Backups)
		state := "completed"
		message := ""
		if e != nil {
			state = "failed"
			message = e.Error()
		}
		b, _ := json.Marshal(map[string]string{"request_id": v.RequestID, "state": state, "message": message, "endpoint": r.Config().Endpoint})
		_ = atomicWrite(filepath.Join(r.Config().DataDir, "endpoint-result.json"), b, 0600)
		reply("probe.endpoint.result", b)
	}()
	return true
}

func (r *Runtime) ValidateWebSocket(ctx context.Context, c Config) error {
	u, _ := url.Parse(c.Endpoint)
	u.Scheme = "wss"
	if c.AllowLocal && strings.HasPrefix(c.Endpoint, "http:") {
		u.Scheme = "ws"
	}
	u.Path = bridge.Prefix + "/node/ws"
	u.RawQuery = "probe_preflight=1"
	d := websocket.Dialer{HandshakeTimeout: 8 * time.Second}
	w, _, e := d.DialContext(ctx, u.String(), http.Header{"Authorization": []string{"Bearer " + c.Secret}, "X-Probe-Device": []string{c.UUID}})
	if e != nil {
		return e
	}
	defer w.Close()
	w.SetReadDeadline(time.Now().Add(8 * time.Second))
	var v struct {
		Event string `json:"event"`
	}
	if e = w.ReadJSON(&v); e != nil {
		return e
	}
	if v.Event != "auth.success" {
		return errors.New("node channel authentication failed")
	}
	return nil
}
