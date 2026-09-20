package integration_test

import (
	"bufio"
	"fmt"
	"io"
	"net"
	"strings"
	"testing"
	"time"

	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	SB "github.com/shini74744/DBoard/node/internal/kernel/singbox"
	XR "github.com/shini74744/DBoard/node/internal/kernel/xray"
	"github.com/shini74744/DBoard/node/internal/model"
)

func startCore(t *testing.T, core, strategy string, exits ...*mockExit) (kernel.Kernel, *model.NodeSpec, string) {
	t.Helper()
	port := freePort(t)
	kcfg := config.KernelConfig{Type: core, LogLevel: "error", ConfigDir: t.TempDir()}
	var k kernel.Kernel
	if core == "xray" {
		k = XR.New(kcfg)
	} else {
		k = SB.New(kcfg)
	}
	n := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: port, Network: "tcp"}
	for _, e := range exits {
		n.CustomOutbounds = append(n.CustomOutbounds, model.OutboundConfig{Tag: e.name, Protocol: "socks", Settings: map[string]any{"server": "127.0.0.1", "server_port": e.port()}})
	}
	n.CustomBalancers = []model.CustomBalancer{{Tag: "test-lb", Strategy: strategy, Selector: []string{exits[0].name, exits[1].name}, ProbeURL: "http://health.test/204", ProbeIntervalSeconds: 5, ProbeTimeoutSeconds: 1}}
	if len(exits) > 2 {
		n.CustomBalancers[0].FallbackTag = exits[2].name
	}
	n.CustomRouteRules = []model.CustomRouteRule{{Action: model.RouteAction{Type: "balancer", Target: "test-lb"}}}
	if err := model.ValidateNodeSpec(n, kcfg); err != nil {
		t.Fatal(err)
	}
	if err := k.Start(n, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(k.Stop)
	return k, n, fmt.Sprintf("127.0.0.1:%d", port)
}
func TestUniversalBalancerRealTCPUDP(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b := newExit(t, "a"), newExit(t, "b")
			_, _, addr := startCore(t, core, "roundRobin", a, b)
			var seq []string
			for i := 0; i < 6; i++ {
				c, s := tcpSession(t, addr)
				seq = append(seq, strings.TrimSpace(s))
				c.Close()
			}
			if strings.Join(seq, ",") != "a,b,a,b,a,b" {
				t.Fatalf("round robin sequence %v", seq)
			}
			ctrl, bound, err := authControl(addr, 3, "0.0.0.0:0")
			if err != nil {
				t.Fatal(err)
			}
			defer ctrl.Close()
			udpAddr, err := boundUDP(bound)
			if err != nil {
				t.Fatal(err)
			}
			u, err := net.Dial("udp", udpAddr)
			if err != nil {
				t.Fatal(err)
			}
			defer u.Close()
			u.SetDeadline(time.Now().Add(3 * time.Second))
			packet := append([]byte{0, 0, 0, 1, 198, 18, 0, 1, 0, 53}, []byte("hello")...)
			var last string
			for i := 0; i < 3; i++ {
				// UDP delivery is not guaranteed. In particular the SOCKS server
				// registers its UDP authorization just after returning ASSOCIATE.
				// Retry the initial datagram only; subsequent packets must keep
				// the same established association and member.
				buf := make([]byte, 512)
				n := 0
				attempts := 1
				if i == 0 {
					attempts = 10
				}
				for attempt := 0; attempt < attempts; attempt++ {
					u.SetDeadline(time.Now().Add(300 * time.Millisecond))
					if _, err = u.Write(packet); err != nil {
						t.Fatal(err)
					}
					n, err = u.Read(buf)
					if err == nil {
						break
					}
					if ne, ok := err.(net.Error); !ok || !ne.Timeout() {
						t.Fatal(err)
					}
				}
				if err != nil {
					t.Fatal(err)
				}
				r := bytesReader(buf[3:n])
				if _, err = readAddress(r); err != nil {
					t.Fatal(err)
				}
				payload, _ := io.ReadAll(r)
				got := string(payload)
				if i > 0 && got != last {
					t.Fatalf("UDP session moved between exits %q->%q", last, got)
				}
				last = got
			}
			if last != "a\n" && last != "b\n" {
				t.Fatalf("unexpected UDP payload %q", last)
			}
		})
	}
}
func TestUniversalBalancerLeastLoad(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b := newExit(t, "a"), newExit(t, "b")
			_, _, addr := startCore(t, core, "least_load", a, b)
			first, x := tcpSession(t, addr)
			defer first.Close()
			second, y := tcpSession(t, addr)
			if x == y {
				t.Fatalf("least load ignored live session: %s", x)
			}
			io.WriteString(second, "QUIT\n")
			io.Copy(io.Discard, second)
			second.Close()
			time.Sleep(100 * time.Millisecond)
			third, z := tcpSession(t, addr)
			defer third.Close()
			if z != y {
				t.Fatalf("closed session count not released: %q %q", y, z)
			}
		})
	}
}
func TestUniversalBalancerFallback(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b, f := newExit(t, "a"), newExit(t, "b"), newExit(t, "fallback")
			a.down.Store(true)
			b.down.Store(true)
			_, _, addr := startCore(t, core, "random", a, b, f)
			deadline := time.Now().Add(8 * time.Second)
			for time.Now().Before(deadline) && (a.probes.Load() < 2 || b.probes.Load() < 2) {
				time.Sleep(50 * time.Millisecond)
			}
			time.Sleep(100 * time.Millisecond)
			c, x := tcpSession(t, addr)
			if x != "fallback\n" {
				t.Fatalf("expected fallback, got %q", x)
			}
			c.Close()
			// An established connection remains pinned to fallback when primary recovers.
			pinned, x := tcpSession(t, addr)
			defer pinned.Close()
			a.down.Store(false)
			b.down.Store(false)
			before := a.probes.Load()
			deadline = time.Now().Add(7 * time.Second)
			for time.Now().Before(deadline) && a.probes.Load() == before {
				time.Sleep(50 * time.Millisecond)
			}
			time.Sleep(100 * time.Millisecond)
			pinned.SetDeadline(time.Now().Add(time.Second))
			io.WriteString(pinned, "PING\n")
			reply, err := bufio.NewReader(pinned).ReadString('\n')
			if err != nil || reply != "fallback\n" {
				t.Fatalf("existing connection moved: %q %v", reply, err)
			}
			fresh, y := tcpSession(t, addr)
			fresh.Close()
			if y == x {
				t.Fatalf("recovered primary was not reused")
			}
		})
	}
}
