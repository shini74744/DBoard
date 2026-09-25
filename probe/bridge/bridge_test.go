package bridge

import (
	"bytes"
	"context"
	"encoding/json"
	"github.com/gorilla/websocket"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const deviceID = "c9a2215c-a675-448e-b6ac-c96dd420a79b"

func TestRegistryEnrollmentAndRevocation(t *testing.T) {
	path := filepath.Join(t.TempDir(), "devices.json")
	s, e := NewRegistry(path)
	if e != nil {
		t.Fatal(e)
	}
	secret := ID()
	code := ID()
	d := Device{UUID: deviceID, Enabled: true, EnrollHash: digest(code), EnrollExpires: time.Now().Add(time.Minute).Unix()}
	if e = s.Put(d); e != nil {
		t.Fatal(e)
	}
	if _, e = s.Enroll(deviceID, "bad", secret); e == nil {
		t.Fatal("bad code accepted")
	}
	if _, e = s.Enroll(deviceID, code, secret); e != nil {
		t.Fatal(e)
	}
	if _, e = s.Enroll(deviceID, code, secret); e != nil {
		t.Fatal("lost response retry failed", e)
	}
	if _, e = s.Enroll(deviceID, code, ID()); e == nil {
		t.Fatal("code reused")
	}
	s, e = NewRegistry(path)
	if e != nil {
		t.Fatal(e)
	}
	if _, ok := s.Authorize(deviceID, secret); !ok {
		t.Fatal("credential not durable")
	}
	d, _ = s.Get(deviceID)
	d.Enabled = false
	s.Put(d)
	if _, ok := s.Authorize(deviceID, secret); ok {
		t.Fatal("disabled device authorized")
	}
}
func TestGatewayHTTPWebSocketAndIsolation(t *testing.T) {
	secret := ID()
	key := ID()
	panelKey := ID()
	s, _ := NewRegistry(filepath.Join(t.TempDir(), "devices.json"))
	s.Put(Device{UUID: deviceID, Enabled: true, SecretHash: digest(secret)})
	var endpoint string
	wsUp := websocket.Upgrader{}
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/ws" {
			if r.URL.Query().Get("machine_id") != "42" || r.URL.Query().Get("token") != "real-panel-key" {
				t.Error("machine binding lost")
				http.Error(w, "bad", 403)
				return
			}
			c, e := wsUp.Upgrade(w, r, nil)
			if e != nil {
				return
			}
			defer c.Close()
			for {
				mt, b, e := c.ReadMessage()
				if e != nil {
					return
				}
				c.WriteMessage(mt, b)
			}
		}
		if bearer(r) != panelKey {
			http.Error(w, "unauthorized", 403)
			return
		}
		if r.URL.Path == "/api/v2/probe/endpoint" {
			writeJSON(w, 200, map[string]string{"endpoint": endpoint})
			return
		}
		if r.URL.Path == "/api/v2/probe/credential" {
			writeJSON(w, 200, map[string]any{"machine_id": 42, "token": "real-panel-key"})
			return
		}
		var f Frame
		json.NewDecoder(r.Body).Decode(&f)
		if f.Device != deviceID || f.Query.Get("token") != "" || f.Query.Get("machine_id") != "" {
			t.Error("untrusted authentication leaked")
		}
		writeJSON(w, 200, Frame{Status: 200, Headers: map[string]string{"Content-Type": "application/json"}, Body: []byte(`{"data":true,"websocket":{"ws_url":"wss://private.invalid/ws"}}`)})
	}))
	defer panel.Close()
	g := &Gateway{Registry: s, ControlKey: key}
	server := httptest.NewServer(g.Handler(http.NotFoundHandler()))
	defer server.Close()
	endpoint = "http://127.0.0.1:1"
	g.Public = server.URL
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	c := Connector{Endpoint: "http://127.0.0.1:1", Backups: []string{server.URL}, Key: key, Panel: panel.URL, PanelKey: panelKey, WebSocket: "ws" + strings.TrimPrefix(panel.URL, "http") + "/ws", AllowLocal: true}
	go c.Run(ctx)
	deadline := time.Now().Add(5 * time.Second)
	for g.online() == nil && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if g.online() == nil {
		t.Fatal("connector not online")
	}
	send := func(path, token string) *http.Response {
		req, _ := http.NewRequest("POST", server.URL+Prefix+path, bytes.NewReader([]byte("{}")))
		req.Header.Set("Authorization", "Bearer "+token)
		req.Header.Set("X-Probe-Device", deviceID)
		r, e := http.DefaultClient.Do(req)
		if e != nil {
			t.Fatal(e)
		}
		return r
	}
	bad := send("/node/api/v2/server/handshake", "bad")
	bad.Body.Close()
	if bad.StatusCode != 403 {
		t.Fatal("bad auth accepted")
	}
	forbidden := send("/node/api/v2/admin/config", secret)
	forbidden.Body.Close()
	if forbidden.StatusCode != 404 {
		t.Fatal("arbitrary proxy path")
	}
	resp := send("/node/api/v2/server/handshake?token=attacker&machine_id=9", secret)
	b, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	if resp.StatusCode != 200 || bytes.Contains(b, []byte("private.invalid")) || !bytes.Contains(b, []byte(Prefix+"/node/ws")) {
		t.Fatal("unsafe handshake", string(b))
	}
	wsURL := "ws" + strings.TrimPrefix(server.URL, "http") + Prefix + "/node/ws"
	ws, _, e := websocket.DefaultDialer.Dial(wsURL, http.Header{"Authorization": []string{"Bearer " + secret}, "X-Probe-Device": []string{deviceID}})
	if e != nil {
		t.Fatal(e)
	}
	defer ws.Close()
	// Real panel sends auth.success first; this echo fixture allows time to establish the upstream.
	time.Sleep(100 * time.Millisecond)
	ws.WriteMessage(websocket.TextMessage, []byte(`{"event":"test"}`))
	ws.SetReadDeadline(time.Now().Add(3 * time.Second))
	_, b, e = ws.ReadMessage()
	if e != nil || string(b) != `{"event":"test"}` {
		t.Fatal("ws relay", e, string(b))
	}
	g.revoke(deviceID)
	ws.SetReadDeadline(time.Now().Add(time.Second))
	if _, _, e = ws.ReadMessage(); e == nil {
		t.Fatal("revoked socket still open")
	}
}
