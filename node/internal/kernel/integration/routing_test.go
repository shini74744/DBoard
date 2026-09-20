package integration_test

import (
	"bufio"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	SB "github.com/shini74744/DBoard/node/internal/kernel/singbox"
	XR "github.com/shini74744/DBoard/node/internal/kernel/xray"
	"github.com/shini74744/DBoard/node/internal/model"
)

func cloneSpec(n *model.NodeSpec) *model.NodeSpec {
	b, _ := json.Marshal(n)
	var out model.NodeSpec
	json.Unmarshal(b, &out)
	return &out
}
func replyFor(addr, target string) (string, error) {
	c, _, err := authControl(addr, 1, target)
	if err != nil {
		return "", err
	}
	defer c.Close()
	io.WriteString(c, "PING\n")
	return bufio.NewReader(c).ReadString('\n')
}
func launchSpec(t *testing.T, core string, n *model.NodeSpec, dir string) (kernel.Kernel, string) {
	t.Helper()
	cfg := config.KernelConfig{Type: core, LogLevel: "error", ConfigDir: dir, GeoDataDir: dir}
	var k kernel.Kernel
	if core == "xray" {
		k = XR.New(cfg)
	} else {
		k = SB.New(cfg)
	}
	if err := k.Start(n, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(k.Stop)
	return k, fmt.Sprintf("127.0.0.1:%d", n.ServerPort)
}
func nodeWithExits(t *testing.T, exits ...*mockExit) *model.NodeSpec {
	n := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: freePort(t), Network: "tcp"}
	for _, e := range exits {
		n.CustomOutbounds = append(n.CustomOutbounds, model.OutboundConfig{Tag: e.name, Protocol: "socks", Settings: map[string]any{"server": "127.0.0.1", "server_port": e.port()}})
	}
	return n
}
func TestRouteAddressRelationAndPortOverRealSockets(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		for _, relation := range []string{"or", "and"} {
			t.Run(core+"/"+relation, func(t *testing.T) {
				a, b := newExit(t, "matched"), newExit(t, "missed")
				n := nodeWithExits(t, a, b)
				n.CustomRouteRules = []model.CustomRouteRule{
					{IPDomainRelation: relation, Match: model.RouteMatch{Domains: []string{"full:localhost", "full:no-such-host.xboard.invalid"}, IPCIDRs: []string{"127.0.0.1/32", "::1/128", "198.18.0.0/24"}, Ports: []string{"443"}, Networks: []string{"tcp"}}, Action: model.RouteAction{Type: "route", Target: "matched"}},
					{Action: model.RouteAction{Type: "route", Target: "missed"}},
				}
				_, addr := launchSpec(t, core, n, t.TempDir())
				cases := []struct{ target, want string }{{"localhost:443", "matched\n"}, {"localhost:80", "missed\n"}, {"198.18.0.1:80", "missed\n"}, {"198.18.0.1:443", "matched\n"}}
				if relation == "and" {
					cases[3].want = "missed\n"
				} else {
					cases = append(cases, struct{ target, want string }{"no-such-host.xboard.invalid:443", "matched\n"})
				}
				cases = append(cases, struct{ target, want string }{"unresolved.xboard.invalid:80", "missed\n"})
				for _, tc := range cases {
					got, err := replyFor(addr, tc.target)
					if err != nil || got != tc.want {
						t.Errorf("%s => %q, want %q (%v)", tc.target, got, tc.want, err)
					}
				}
			})
		}
	}
}
func TestSniffedHTTPKeywordAndRegexp(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b := newExit(t, "match"), newExit(t, "other")
			n := nodeWithExits(t, a, b)
			n.CustomRouteRules = []model.CustomRouteRule{{Match: model.RouteMatch{Domains: []string{"keyword:example", "regexp:^api\\.test$"}, Protocols: []string{"http"}}, Action: model.RouteAction{Type: "route", Target: "match"}}, {Action: model.RouteAction{Type: "route", Target: "other"}}}
			_, addr := launchSpec(t, core, n, t.TempDir())
			for host, want := range map[string]string{"www.example.test": "match", "api.test": "match", "no-match.test": "other"} {
				c, _, err := authControl(addr, 1, "198.18.0.1:80")
				if err != nil {
					t.Fatal(err)
				}
				io.WriteString(c, "GET / HTTP/1.1\r\nHost: "+host+"\r\nConnection: close\r\n\r\n")
				response, err := http.ReadResponse(bufio.NewReader(c), nil)
				if err != nil {
					c.Close()
					t.Fatal(err)
				}
				got := response.Header.Get("X-Test-Exit")
				response.Body.Close()
				c.Close()
				if got != want {
					t.Errorf("Host=%s routed to %q instead of %q", host, got, want)
				}
			}
		})
	}
}
func TestSniffedTLSKeywordRoute(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			match, other := newExit(t, "match"), newExit(t, "other")
			n := nodeWithExits(t, match, other)
			n.CustomRouteRules = []model.CustomRouteRule{
				{Match: model.RouteMatch{Domains: []string{"keyword:ipleak"}}, Action: model.RouteAction{Type: "route", Target: "match"}},
				{Action: model.RouteAction{Type: "route", Target: "other"}},
			}
			_, addr := launchSpec(t, core, n, t.TempDir())

			sendHello := func(serverName string) {
				t.Helper()
				beforeMatch, beforeOther := match.connects.Load(), other.connects.Load()
				c, _, err := authControl(addr, 1, "198.18.0.1:443")
				if err != nil {
					t.Fatal(err)
				}
				tc := tls.Client(c, &tls.Config{
					ServerName: serverName,
					MinVersion: tls.VersionTLS12,
				})
				_ = tc.SetDeadline(time.Now().Add(750 * time.Millisecond))
				_ = tc.Handshake()
				_ = tc.Close()

				deadline := time.Now().Add(time.Second)
				for time.Now().Before(deadline) &&
					match.connects.Load() == beforeMatch &&
					other.connects.Load() == beforeOther {
					time.Sleep(10 * time.Millisecond)
				}
				if strings.Contains(serverName, "ipleak") {
					if match.connects.Load() <= beforeMatch || other.connects.Load() != beforeOther {
						t.Fatalf("TLS SNI %s did not route to keyword member", serverName)
					}
				} else if other.connects.Load() <= beforeOther || match.connects.Load() != beforeMatch {
					t.Fatalf("TLS SNI %s did not use fallback route", serverName)
				}
			}

			sendHello("www.ipleak.net")
			sendHello("www.example.net")
		})
	}
}

func TestReloadMembershipAndInvalidConfigRollback(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b, c := newExit(t, "a"), newExit(t, "b"), newExit(t, "c")
			k, n, addr := startCore(t, core, "roundRobin", a, b, c)
			updated := cloneSpec(n)
			updated.CustomBalancers[0].Selector = []string{"b", "c"}
			updated.CustomBalancers[0].FallbackTag = ""
			if err := k.Reload(updated, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			time.Sleep(1200 * time.Millisecond)
			for i := 0; i < 4; i++ {
				conn, x := tcpSession(t, addr)
				conn.Close()
				if x == "a\n" {
					t.Fatal("old member remained after reload")
				}
			}
			invalid := cloneSpec(updated)
			invalid.CustomBalancers[0].Selector = []string{"b", "missing"}
			if err := k.Reload(invalid, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err == nil {
				t.Fatal("invalid config was accepted")
			}
			if !k.IsRunning() {
				t.Fatal("invalid config stopped running core")
			}
			conn, _ := tcpSession(t, addr)
			conn.Close()
			// Rule-only reload uses the latest group and does not switch the backend.
			rulesOnly := cloneSpec(updated)
			rulesOnly.CustomRouteRules = []model.CustomRouteRule{{Action: model.RouteAction{Type: "route", Target: "c"}}}
			if err := k.Reload(rulesOnly, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			time.Sleep(1200 * time.Millisecond)
			conn, x := tcpSession(t, addr)
			conn.Close()
			if x != "c\n" {
				t.Fatalf("rule reload not applied %q", x)
			}
			if !strings.Contains(strings.ReplaceAll(k.Name(), "-", ""), core) {
				t.Fatalf("kernel switched to %s", k.Name())
			}
		})
	}
}
