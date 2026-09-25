package probeagent

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/shini74744/DBoard/probe/bridge"
)

func TestDurableReportAndGatewayOnlyTransport(t *testing.T) {
	seen := 0
	var batch string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Probe-Device") == "" || r.Header.Get("Authorization") == "" {
			t.Error("missing scoped credentials")
		}
		if r.URL.Query().Get("token") != "" || r.URL.Query().Get("machine_id") != "" {
			t.Error("legacy identity leaked")
		}
		seen++
		if batch != "" && batch != r.Header.Get("X-Probe-Request-ID") {
			t.Error("retry identity changed")
		}
		batch = r.Header.Get("X-Probe-Request-ID")
		if seen == 1 {
			http.Error(w, "not ready", 503)
			return
		}
		io.WriteString(w, `{"data":true}`)
	}))
	defer server.Close()
	c := Config{Endpoint: server.URL, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Secret: bridge.ID(), DataDir: t.TempDir(), AllowLocal: true}
	p := filepath.Join(c.DataDir, "config.json")
	rt, _ := New(c, p)
	req, _ := http.NewRequest("POST", "https://never-dial.invalid"+bridge.Prefix+"/node/api/v2/server/report?token=bad&machine_id=999", strings.NewReader(`{"traffic":{"1":[2,3]}}`))
	res, e := rt.RoundTrip(req)
	if e != nil || res.StatusCode != 200 {
		t.Fatal(e)
	}
	res.Body.Close()
	if seen != 0 {
		t.Fatal("outbox not durable before acknowledgment")
	}
	files, _ := os.ReadDir(filepath.Join(c.DataDir, "outbox"))
	if len(files) != 1 {
		t.Fatal("missing outbox file")
	}
	if e = rt.flush(context.Background()); e == nil {
		t.Fatal("failure not retained")
	}
	// Recreate runtime to simulate process restart; batch ID must be retained.
	rt, _ = New(c, p)
	if e = rt.flush(context.Background()); e != nil {
		t.Fatal(e)
	}
	files, _ = os.ReadDir(filepath.Join(c.DataDir, "outbox"))
	if len(files) != 0 || seen != 2 {
		t.Fatal("outbox retry failed")
	}
	forbidden, _ := http.NewRequest("GET", server.URL+"/not-probe", nil)
	if _, e = rt.RoundTrip(forbidden); e == nil {
		t.Fatal("arbitrary management request accepted")
	}
}
func TestEndpointSwitchPersistsAndFailureKeepsOld(t *testing.T) {
	good := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "{}") }))
	defer good.Close()
	bad := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Error(w, "bad", 403) }))
	defer bad.Close()
	c := Config{Endpoint: good.URL, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Secret: bridge.ID(), DataDir: t.TempDir(), AllowLocal: true}
	p := filepath.Join(c.DataDir, "config.json")
	rt, _ := New(c, p)
	if e := rt.Switch(context.Background(), bad.URL, []string{good.URL}); e == nil {
		t.Fatal("bad candidate accepted")
	}
	if rt.Config().Endpoint != good.URL {
		t.Fatal("old endpoint lost")
	}
	if e := rt.Switch(context.Background(), good.URL, []string{bad.URL}); e != nil {
		t.Fatal(e)
	}
	b, _ := os.ReadFile(p)
	var saved Config
	json.Unmarshal(b, &saved)
	if saved.Endpoint != good.URL || len(saved.Backups) != 1 {
		t.Fatal("migration not persisted")
	}
	u, _ := url.Parse("https://private.invalid/ws")
	if _, _, e := rt.DialWS(context.Background(), u.String()); e == nil {
		t.Fatal("arbitrary websocket allowed")
	}
}
