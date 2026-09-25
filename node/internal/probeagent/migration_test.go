package probeagent

import (
	"context"
	"errors"
	"github.com/shini74744/DBoard/probe/bridge"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestBackupReconnectAndPreflightFailure(t *testing.T) {
	good := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "{}") }))
	defer good.Close()
	c := Config{Endpoint: "http://127.0.0.1:1", Backups: []string{good.URL}, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Secret: bridge.ID(), DataDir: t.TempDir(), AllowLocal: true}
	path := filepath.Join(c.DataDir, "config.json")
	r, e := New(c, path)
	if e != nil {
		t.Fatal(e)
	}
	r.ValidateEndpoint = func(context.Context, Config) error { return errors.New("monitor TLS failed") }
	r.tryBackups(context.Background())
	if r.Config().Endpoint != c.Endpoint {
		t.Fatal("HTTP alone allowed migration despite monitor check failure")
	}
	called := false
	r.ValidateEndpoint = func(context.Context, Config) error { called = true; return nil }
	r.tryBackups(context.Background())
	if !called || r.Config().Endpoint != good.URL || r.Config().Backups[0] != c.Endpoint {
		t.Fatal("backup not selected or old origin lost")
	}
}

func TestPermanentReportFailureDoesNotBlockOtherNodes(t *testing.T) {
	count := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		count++
		if count == 1 {
			http.Error(w, "removed node", 404)
			return
		}
		io.WriteString(w, "{}")
	}))
	defer server.Close()
	c := Config{Endpoint: server.URL, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Secret: bridge.ID(), DataDir: t.TempDir(), AllowLocal: true}
	rt, _ := New(c, filepath.Join(c.DataDir, "config.json"))
	for i := 0; i < 2; i++ {
		req, _ := http.NewRequest("POST", server.URL+bridge.Prefix+"/node/api/v2/server/report", strings.NewReader("{}"))
		resp, e := rt.RoundTrip(req)
		if e != nil {
			t.Fatal(e)
		}
		resp.Body.Close()
	}
	if e := rt.flush(context.Background()); e != nil {
		t.Fatal(e)
	}
	pending, _ := os.ReadDir(filepath.Join(c.DataDir, "outbox"))
	quarantine, _ := os.ReadDir(filepath.Join(c.DataDir, "quarantine"))
	if len(pending) != 0 || len(quarantine) != 1 || count != 2 {
		t.Fatal("removed node blocked later reports")
	}
}
