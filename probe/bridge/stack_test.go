package bridge

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"github.com/gorilla/websocket"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// Opt-in: uses only loopback listeners, temporary credentials and a temporary
// Nezha database. No installed service or existing panel is changed.
func TestBuiltIntegratedStack(t *testing.T) {
	agent := os.Getenv("PROBE_TEST_AGENT")
	dashboard := os.Getenv("PROBE_TEST_DASHBOARD")
	if agent == "" || dashboard == "" {
		t.Skip("set PROBE_TEST_AGENT and PROBE_TEST_DASHBOARD after building")
	}
	dir := t.TempDir()
	port := func() int {
		l, e := net.Listen("tcp", "127.0.0.1:0")
		if e != nil {
			t.Fatal(e)
		}
		defer l.Close()
		return l.Addr().(*net.TCPAddr).Port
	}
	tlsPort, httpPort, nodePort := port(), port(), port()
	endpoint := fmt.Sprintf("https://127.0.0.1:%d", tlsPort)
	next := fmt.Sprintf("https://localhost:%d", tlsPort)
	certPath, keyPath := filepath.Join(dir, "ca.pem"), filepath.Join(dir, "key.pem")
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	template := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "probe test"}, DNSNames: []string{"localhost"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
	der, _ := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0600)
	kb, _ := x509.MarshalECPrivateKey(key)
	os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}), 0600)
	roots := x509.NewCertPool()
	roots.AddCert(func() *x509.Certificate { x, _ := x509.ParseCertificate(der); return x }())
	tc := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12}
	client := &http.Client{Transport: &http.Transport{TLSClientConfig: tc}, Timeout: 5 * time.Second}
	oldTLS := websocket.DefaultDialer.TLSClientConfig
	websocket.DefaultDialer.TLSClientConfig = tc
	defer func() { websocket.DefaultDialer.TLSClientConfig = oldTLS }()
	keyFile := filepath.Join(dir, "control.key")
	controlKey := ID()
	panelKey := ID()
	os.WriteFile(keyFile, []byte(controlKey), 0600)
	conf := filepath.Join(dir, "dashboard.yaml")
	os.WriteFile(conf, []byte(fmt.Sprintf("listen_host: 127.0.0.1\nlisten_port: %d\nhttps:\n  listen_port: %d\n  tls_cert_path: %s\n  tls_key_path: %s\n", httpPort, tlsPort, certPath, keyPath)), 0600)
	logs := map[string]string{}
	start := func(name, path string, env []string, args ...string) *exec.Cmd {
		f, e := os.Create(filepath.Join(dir, name+".log"))
		if e != nil {
			t.Fatal(e)
		}
		logs[name] = f.Name()
		cmd := exec.Command(path, args...)
		cmd.Dir = dir
		cmd.Env = append(os.Environ(), env...)
		cmd.Stdout = f
		cmd.Stderr = f
		if e = cmd.Start(); e != nil {
			t.Fatal(e)
		}
		t.Cleanup(func() { cmd.Process.Kill(); cmd.Wait(); f.Close() })
		return cmd
	}
	defer func() {
		if t.Failed() {
			for name, p := range logs {
				b, _ := os.ReadFile(p)
				t.Log(name, string(b))
			}
		}
	}()
	start("dashboard", dashboard, []string{"NEZHA_BRIDGE_URL=" + endpoint, "NEZHA_BRIDGE_KEY_FILE=" + keyFile, "NEZHA_BRIDGE_OWNER_ID=1", "NEZHA_BRIDGE_DATA=" + filepath.Join(dir, "bridge")}, "-c", conf, "-db", filepath.Join(dir, "dashboard.db"))
	call := func(method, path, token string, v any) (int, []byte, error) {
		b, _ := json.Marshal(v)
		req, _ := http.NewRequest(method, endpoint+path, bytes.NewReader(b))
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Authorization", "Bearer "+token)
		res, e := client.Do(req)
		if e != nil {
			return 0, nil, e
		}
		defer res.Body.Close()
		b, e = io.ReadAll(res.Body)
		return res.StatusCode, b, e
	}
	wait := func(label string, limit time.Duration, fn func() bool) {
		deadline := time.Now().Add(limit)
		for time.Now().Before(deadline) {
			if fn() {
				return
			}
			time.Sleep(200 * time.Millisecond)
		}
		t.Fatal("timed out: " + label)
	}
	wait("dashboard", 30*time.Second, func() bool {
		s, _, e := call("GET", Prefix+"/control/status", controlKey, nil)
		return e == nil && s == 200
	})
	code := ID()
	s, _, e := call("POST", Prefix+"/control/device", controlKey, map[string]any{"uuid": deviceID, "name": "Integrated smoke test", "enabled": true, "code": code})
	if e != nil || s != 200 {
		t.Fatalf("provision %d %v", s, e)
	}
	var desired atomic.Value
	desired.Store(endpoint)
	var mu sync.Mutex
	sockets := map[*websocket.Conn]bool{}
	var reports atomic.Int64
	var transferred atomic.Int64
	var lastEndpoint atomic.Value
	lastEndpoint.Store("")
	userUUID := "016336d2-54ea-4e96-b323-83b882bc11f8"
	up := websocket.Upgrader{}
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/ws" {
			c, e := up.Upgrade(w, r, nil)
			if e != nil {
				return
			}
			defer c.Close()
			c.WriteJSON(map[string]any{"event": "auth.success", "data": map[string]any{}})
			if r.URL.Query().Get("probe_preflight") == "1" {
				return
			}
			lastEndpoint.Store(r.URL.Query().Get("probe_endpoint"))
			mu.Lock()
			sockets[c] = true
			mu.Unlock()
			defer func() { mu.Lock(); delete(sockets, c); mu.Unlock() }()
			for {
				if _, _, e = c.ReadMessage(); e != nil {
					return
				}
			}
		}
		if bearer(r) != panelKey {
			http.Error(w, "bad key", 403)
			return
		}
		switch r.URL.Path {
		case "/api/v2/probe/endpoint":
			writeJSON(w, 200, map[string]any{"endpoint": desired.Load()})
			return
		case "/api/v2/probe/credential":
			writeJSON(w, 200, map[string]any{"machine_id": 42, "token": "internal-only"})
			return
		}
		var frame Frame
		json.NewDecoder(r.Body).Decode(&frame)
		var body any = map[string]any{"data": true}
		switch frame.Path {
		case "/api/v2/server/machine/nodes":
			body = map[string]any{"nodes": []any{map[string]any{"id": 1, "type": "vless", "name": "local smoke"}}, "base_config": map[string]int{"push_interval": 2, "pull_interval": 2}}
		case "/api/v2/server/config":
			body = map[string]any{"protocol": "vless", "listen_ip": "127.0.0.1", "server_port": nodePort, "network": "tcp", "tls": 0, "custom_route_rules": []any{map[string]any{"action": map[string]any{"type": "direct"}}}, "base_config": map[string]int{"push_interval": 2, "pull_interval": 2}}
		case "/api/v2/server/user":
			body = map[string]any{"users": []any{map[string]any{"id": 7, "uuid": userUUID}}}
		case "/api/v2/server/report":
			reports.Add(1)
			var data struct {
				Traffic map[string][2]int64 `json:"traffic"`
			}
			json.Unmarshal(frame.Body, &data)
			for _, v := range data.Traffic {
				transferred.Add(v[0] + v[1])
			}
		}
		b, _ := json.Marshal(body)
		writeJSON(w, 200, Frame{Status: 200, Body: b})
	}))
	defer panel.Close()
	connCtx, stopConnector := context.WithCancel(context.Background())
	defer stopConnector()
	connectorDone := make(chan struct{})
	go func() {
		defer close(connectorDone)
		c := Connector{Endpoint: endpoint, Key: controlKey, Panel: panel.URL, PanelKey: panelKey, WebSocket: "ws" + strings.TrimPrefix(panel.URL, "http") + "/ws", Client: client}
		c.Run(connCtx)
	}()
	wait("connector", 10*time.Second, func() bool {
		_, b, _ := call("GET", Prefix+"/control/status", controlKey, nil)
		return bytes.Contains(b, []byte(`"connected":true`))
	})
	// Exercise the actual installer enrollment mode of the combined binary.
	cfgPath := filepath.Join(dir, "agent.json")
	enroll := exec.Command(agent, "--enroll", "--endpoint", endpoint, "--uuid", deviceID, "--enrollment", code, "-c", cfgPath)
	enroll.Env = append(os.Environ(), "SSL_CERT_FILE="+certPath)
	if out, e := enroll.CombinedOutput(); e != nil {
		t.Fatalf("enroll %v %s", e, out)
	}
	var cfg map[string]any
	b, _ := os.ReadFile(cfgPath)
	json.Unmarshal(b, &cfg)
	cfg["data_dir"] = filepath.Join(dir, "agent-data")
	b, _ = json.Marshal(cfg)
	os.WriteFile(cfgPath, b, 0600)
	runAgent := func(name string) *exec.Cmd {
		return start(name, agent, []string{"SSL_CERT_FILE=" + certPath}, "-c", cfgPath)
	}
	process := runAgent("agent")
	pingCtx, stopPing := context.WithCancel(context.Background())
	defer stopPing()
	go func() {
		timer := time.NewTicker(time.Second)
		defer timer.Stop()
		for {
			select {
			case <-pingCtx.Done():
				return
			case <-timer.C:
				mu.Lock()
				for c := range sockets {
					c.WriteJSON(map[string]any{"event": "ping"})
				}
				mu.Unlock()
			}
		}
	}()
	healthy := func() bool {
		b, e := os.ReadFile(filepath.Join(dir, "agent-data", "health.json"))
		return e == nil && bytes.Contains(b, []byte(`"monitoring":true`))
	}
	wait("both live monitoring and node channel", 35*time.Second, healthy)
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "probe-proxy-ok") }))
	defer target.Close()
	proxy := func() bool {
		c, e := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", nodePort), time.Second)
		if e != nil {
			return false
		}
		defer c.Close()
		c.SetDeadline(time.Now().Add(3 * time.Second))
		id, _ := hex.DecodeString(strings.ReplaceAll(userUUID, "-", ""))
		host, portStr, _ := net.SplitHostPort(strings.TrimPrefix(target.URL, "http://"))
		var dest int
		fmt.Sscan(portStr, &dest)
		header := append([]byte{0}, id...)
		header = append(header, 0, 1)
		pb := make([]byte, 2)
		binary.BigEndian.PutUint16(pb, uint16(dest))
		header = append(header, pb...)
		header = append(header, 1)
		header = append(header, net.ParseIP(host).To4()...)
		header = append(header, []byte("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n")...)
		if _, e = c.Write(header); e != nil {
			return false
		}
		reply, e := io.ReadAll(c)
		return e == nil && bytes.Contains(reply, []byte("probe-proxy-ok"))
	}
	wait("real VLESS proxy", 20*time.Second, proxy)
	wait("traffic via gateway", 15*time.Second, func() bool { return transferred.Load() > 0 && reports.Load() > 0 })
	// Persist a new origin and update both monitoring and node control transports.
	mu.Lock()
	for c := range sockets {
		c.WriteJSON(map[string]any{"event": "probe.endpoint", "data": map[string]any{"request_id": "migration-test", "endpoint": next, "backups": []string{endpoint}}})
	}
	mu.Unlock()
	desired.Store(next)
	wait("migration saved", 25*time.Second, func() bool {
		b, _ := os.ReadFile(cfgPath)
		var c map[string]any
		json.Unmarshal(b, &c)
		return c["endpoint"] == next && lastEndpoint.Load() == next
	})
	wait("proxy after migration", 15*time.Second, proxy)
	process.Process.Signal(os.Interrupt)
	process.Wait()
	runAgent("agent-restarted")
	wait("restart uses persisted endpoint", 25*time.Second, func() bool { return lastEndpoint.Load() == next && healthy() && proxy() })
	// Monitoring backend is still the real Nezha API and UI.
	s, b, e = call("GET", "/", "", nil)
	if e != nil || s != 200 || !bytes.Contains(b, []byte("<html")) {
		t.Fatal("monitor frontend unavailable", s, e)
	}
	s, b, e = call("POST", "/api/v1/login", "", map[string]string{"username": "admin", "password": "admin"})
	if e != nil || s != 200 {
		t.Fatal("monitor login failed", s, e)
	}
	var login struct {
		Data struct {
			Token string `json:"token"`
		} `json:"data"`
	}
	json.Unmarshal(b, &login)
	s, b, e = call("GET", "/api/v1/server", login.Data.Token, nil)
	if e != nil || s != 200 || !bytes.Contains(b, []byte("Integrated smoke test")) {
		t.Fatal("monitor server record missing", s, string(b), e)
	}
	t.Logf("PASS real Dashboard + single Agent, live monitoring, VLESS transfer (%d bytes reported), address migration and restart", transferred.Load())
}
