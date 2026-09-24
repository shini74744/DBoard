package xray

import (
	"context"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	"github.com/shini74744/DBoard/node/internal/model"
	"github.com/shini74744/DBoard/node/internal/panel"
	"golang.org/x/net/proxy"
)

func TestNodeRelayDedicatedIdentity(t *testing.T) {
	port := func() int {
		l, e := net.Listen("tcp", "127.0.0.1:0")
		if e != nil {
			t.Fatal(e)
		}
		p := l.Addr().(*net.TCPAddr).Port
		l.Close()
		return p
	}
	exitPort, entryPort := port(), port()
	uuid := "17d01de3-7db3-4246-aa52-40bdd7a5e101"
	exit := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
	if err := exit.Start(testNodeSpec(&panel.NodeConfig{Protocol: "vless", ListenIP: "127.0.0.1", ServerPort: exitPort, Network: "tcp", CustomRouteRules: []panel.CustomRouteRule{{Name: "test localhost", Action: panel.RouteAction{Type: "direct"}}}}), []model.UserSpec{{ID: -42, UUID: uuid}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	defer exit.Stop()
	entry := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
	nc := &panel.NodeConfig{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: entryPort,
		CustomOutbounds:  []panel.OutboundConfig{{ID: 51, Tag: "node-exit", Protocol: "vless", Settings: map[string]any{"server": "127.0.0.1", "server_port": exitPort, "uuid": uuid, "network": "tcp", "tls_mode": "none"}}},
		CustomRouteRules: []panel.CustomRouteRule{{Name: "exit", Action: panel.RouteAction{Type: "route", Target: "node-exit"}}}}
	if err := entry.Start(testNodeSpec(nc), []model.UserSpec{{ID: 1, UUID: uuid}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	defer entry.Stop()
	web := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "node-relay-ok") }))
	defer web.Close()
	dialer, err := proxy.SOCKS5("tcp", "127.0.0.1:"+strconv.Itoa(entryPort), &proxy.Auth{User: uuid, Password: uuid}, proxy.Direct)
	if err != nil {
		t.Fatal(err)
	}
	client := &http.Client{Timeout: 5 * time.Second, Transport: &http.Transport{DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		return dialer.Dial(network, address)
	}}}
	response, err := client.Get(web.URL)
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	data, _ := io.ReadAll(response.Body)
	if string(data) != "node-relay-ok" {
		t.Fatalf("unexpected response: %s", data)
	}
	connectionSnapshot := entry.ConnectionStats()
	if len(connectionSnapshot.Sources) == 0 || connectionSnapshot.Sources[0].Value != "127.0.0.1" || len(connectionSnapshot.TCPRows) == 0 || connectionSnapshot.TCPRows[0].Count < 1 || connectionSnapshot.TCPRows[0].UserID != 1 || connectionSnapshot.Sources[0].UserID != 1 {
		t.Fatalf("real proxy connection metadata missing: %+v", connectionSnapshot)
	}
	snapshot := entry.GetOutboundTraffic()
	for deadline := time.Now().Add(time.Second); time.Now().Before(deadline) && snapshot.Traffic[51][0] == 0; {
		time.Sleep(10 * time.Millisecond)
		snapshot = entry.GetOutboundTraffic()
	}
	if v := snapshot.Traffic[51]; v[0] <= 0 || v[1] <= 0 {
		t.Fatalf("outbound traffic missing: %+v", snapshot)
	}
	again := entry.GetOutboundTraffic()
	if again.Session != snapshot.Session || again.Traffic[51] != snapshot.Traffic[51] {
		t.Fatalf("snapshot repeated or reset: %+v %+v", snapshot, again)
	}
	traffic, _, _, err := exit.GetUserTraffic(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("relay accepted negative internal identity; traffic records=%d", len(traffic))
}

func TestNodeRelayDisabledExitConfiguration(t *testing.T) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := l.Addr().(*net.TCPAddr).Port
	l.Close()
	instance := New(config.KernelConfig{Type: "xray", LogLevel: "error"})
	nc := testNodeSpec(&panel.NodeConfig{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: port,
		CustomOutbounds:  []panel.OutboundConfig{{Tag: "disabled-node", Protocol: "socks", Settings: map[string]any{"server": "node-unavailable.invalid", "server_port": 1}}},
		CustomRouteRules: []panel.CustomRouteRule{{Name: "disabled", Action: panel.RouteAction{Type: "route", Target: "disabled-node"}}}})
	if err := instance.Start(nc, []model.UserSpec{{ID: 1, UUID: "17d01de3-7db3-4246-aa52-40bdd7a5e101"}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	instance.Stop()
}
