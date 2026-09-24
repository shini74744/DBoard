package integration_test

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net"
	"testing"
	"time"

	"github.com/shini74744/DBoard/node/internal/kernel"
	"github.com/shini74744/DBoard/node/internal/model"
	"github.com/shini74744/DBoard/node/internal/panel"
)

func frontIdentity(t *testing.T, name string) kernel.TLSCert {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	serial, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	template := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: name}, DNSNames: []string{name}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth, x509.ExtKeyUsageClientAuth}, BasicConstraintsValid: true}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	private, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}
	return kernel.TLSCert{CertPEM: pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), KeyPEM: pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: private})}
}
func frontOutbound(tag string, id, port int, server, client kernel.TLSCert) model.OutboundConfig {
	return model.OutboundConfig{ID: id, Tag: tag, Protocol: "vless", Settings: map[string]any{
		"server": "127.0.0.1", "server_port": port, "uuid": userID, "network": "tcp", "tls_mode": "tls", "server_name": "landing.front.test", "front_gate_version": 1,
		"certificate_pem": string(server.CertPEM), "client_certificate_pem": string(client.CertPEM), "client_key_pem": string(client.KeyPEM),
	}}
}
func frontUDP(t *testing.T, addr string, port byte) string {
	t.Helper()
	ctrl, bound, err := authControl(addr, 3, "0.0.0.0:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ctrl.Close()
	relay, err := boundUDP(bound)
	if err != nil {
		t.Fatal(err)
	}
	c, err := net.Dial("udp", relay)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	request := append([]byte{0, 0, 0, 1, 198, 18, 0, 1, 0, port}, []byte("hello")...)
	result := make([]byte, 512)
	var n int
	for i := 0; i < 10; i++ {
		c.SetDeadline(time.Now().Add(350 * time.Millisecond))
		_, err = c.Write(request)
		if err != nil {
			t.Fatal(err)
		}
		n, err = c.Read(result)
		if err == nil {
			break
		}
	}
	if err != nil {
		t.Fatal(err)
	}
	reader := bytesReader(result[3:n])
	if _, err = readAddress(reader); err != nil {
		t.Fatal(err)
	}
	value, _ := io.ReadAll(reader)
	return string(value)
}
func TestFrontGateRealAuthRoutingAndRevocation(t *testing.T) {
	for _, bcore := range []string{"xray", "singbox"} {
		for _, acore := range []string{"xray", "singbox"} {
			t.Run(acore+"-to-"+bcore, func(t *testing.T) {
				server, a, c, outsider := frontIdentity(t, "landing.front.test"), frontIdentity(t, "front-a.test"), frontIdentity(t, "front-c.test"), frontIdentity(t, "outsider.test")
				exit := newExit(t, "LANDING")
				cfg := &panel.NodeConfig{Protocol: "dboard-front-only", ListenIP: "127.0.0.1", ServerPort: freePort(t), FrontGate: &panel.FrontGateConfig{Version: 1, OriginalProtocol: "shadowsocks", Certificate: string(server.CertPEM), PrivateKey: string(server.KeyPEM), TrustedClients: []string{string(a.CertPEM), string(c.CertPEM)}, Revision: "initial"},
					CustomOutbounds:  []panel.OutboundConfig{{Tag: "landing-egress", Protocol: "socks", Settings: map[string]any{"server": "127.0.0.1", "server_port": exit.port()}}},
					CustomRouteRules: []panel.CustomRouteRule{{Action: panel.RouteAction{Type: "route", Target: "landing-egress"}}},
				}
				bn := model.NodeSpecFromPanel(cfg)
				if bn.InboundTag() != "shadowsocks-in" {
					t.Fatal("original inbound routing tag changed")
				}
				b, _ := launchSpec(t, bcore, bn, t.TempDir())
				hopNode := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: freePort(t), CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}}
				_, _ = launchSpec(t, acore, hopNode, t.TempDir())
				an := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: freePort(t)}
				an.CustomOutbounds = []model.OutboundConfig{frontOutbound("allowed-a", 31, bn.ServerPort, server, a), frontOutbound("allowed-c", 32, bn.ServerPort, server, c), frontOutbound("chain", 33, bn.ServerPort, server, a), frontOutbound("outsider", 34, bn.ServerPort, server, outsider), frontOutbound("no-cert", 35, bn.ServerPort, server, kernel.TLSCert{}), frontOutbound("wrong-server", 36, bn.ServerPort, outsider, a),
					{ID: 37, Tag: "hop", Protocol: "socks", Settings: map[string]any{"server": "127.0.0.1", "server_port": hopNode.ServerPort, "username": userID, "password": userID}},
				}
				an.CustomOutbounds[2].ProxyTag = "hop"
				// A normal subscription UUID/password is insufficient: without the server-only certificate, the connection fails.
				delete(an.CustomOutbounds[4].Settings, "front_gate_version")
				delete(an.CustomOutbounds[4].Settings, "client_certificate_pem")
				delete(an.CustomOutbounds[4].Settings, "client_key_pem")
				for port, tag := range map[string]string{"80": "allowed-a", "81": "allowed-c", "83": "chain", "85": "outsider", "86": "no-cert", "87": "wrong-server"} {
					an.CustomRouteRules = append(an.CustomRouteRules, model.CustomRouteRule{Match: model.RouteMatch{Ports: []string{port}}, Action: model.RouteAction{Type: "route", Target: tag}})
				}
				an.CustomBalancers = []model.CustomBalancer{{Tag: "allowed-pool", Strategy: "roundRobin", Selector: []string{"allowed-a", "allowed-c"}}}
				an.CustomRouteRules = append(an.CustomRouteRules, model.CustomRouteRule{Match: model.RouteMatch{Ports: []string{"82"}}, Action: model.RouteAction{Type: "block"}}, model.CustomRouteRule{Match: model.RouteMatch{Ports: []string{"84"}}, Action: model.RouteAction{Type: "balancer", Target: "allowed-pool"}}, model.CustomRouteRule{Action: model.RouteAction{Type: "direct"}})
				entry, addr := launchSpec(t, acore, an, t.TempDir())
				for _, port := range []int{80, 81, 83, 84, 84} {
					reply, err := replyFor(addr, fmt.Sprintf("198.18.0.1:%d", port))
					if err != nil || reply != "LANDING\n" {
						t.Fatalf("route %d: %q %v", port, reply, err)
					}
				}
				direct, err := replyFor(addr, echoDestination(t))
				if err != nil || direct != "PING\n" {
					t.Fatalf("direct route %q %v", direct, err)
				}
				for _, port := range []int{82, 85, 86, 87} {
					reply, err := replyFor(addr, fmt.Sprintf("198.18.0.1:%d", port))
					if err == nil {
						t.Fatalf("unauthorized/block %d admitted: %q", port, reply)
					}
				}
				for _, port := range []byte{80, 81, 83, 84} {
					if reply := frontUDP(t, addr, port); reply != "LANDING\n" {
						t.Fatalf("UDP route %d: %q", port, reply)
					}
				}
				if traffic, ok := entry.(kernel.OutboundTrafficProvider); ok {
					snapshot := traffic.GetOutboundTraffic()
					for _, id := range []int{31, 32, 33, 37} {
						if snapshot.Traffic[id][0] <= 0 || snapshot.Traffic[id][1] <= 0 {
							t.Fatalf("missing original outbound statistics for %d", id)
						}
					}
				}
				revoked := cloneSpec(bn)
				revoked.FrontGate.TrustedClients = []string{string(c.CertPEM)}
				revoked.FrontGate.Revision = "revoked-a"
				if err = b.Reload(revoked, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
					t.Fatal(err)
				}
				time.Sleep(100 * time.Millisecond)
				if reply, err := replyFor(addr, "198.18.0.1:80"); err == nil {
					t.Fatalf("revoked front admitted %q", reply)
				}
				if reply, err := replyFor(addr, "198.18.0.1:81"); err != nil || reply != "LANDING\n" {
					t.Fatalf("remaining front failed %q %v", reply, err)
				}
				empty := cloneSpec(revoked)
				empty.FrontGate.TrustedClients = []string{}
				if err = b.Reload(empty, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
					t.Fatal(err)
				}
				time.Sleep(100 * time.Millisecond)
				if reply, err := replyFor(addr, "198.18.0.1:81"); err == nil {
					t.Fatalf("empty allowed list admitted %q", reply)
				}
			})
		}
	}
}

func TestFrontGateRevokesEstablishedConnections(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			server, a := frontIdentity(t, "landing.front.test"), frontIdentity(t, "front-a.test")
			n := model.NodeSpecFromPanel(&panel.NodeConfig{Protocol: "dboard-front-only", ListenIP: "127.0.0.1", ServerPort: freePort(t), FrontGate: &panel.FrontGateConfig{Version: 1, OriginalProtocol: "vless", Certificate: string(server.CertPEM), PrivateKey: string(server.KeyPEM), TrustedClients: []string{string(a.CertPEM)}}})
			n.CustomRouteRules = []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}
			b, _ := launchSpec(t, core, n, t.TempDir())
			entry := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: freePort(t), CustomOutbounds: []model.OutboundConfig{frontOutbound("landing", 1, n.ServerPort, server, a)}, CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "route", Target: "landing"}}}}
			_, addr := launchSpec(t, "singbox", entry, t.TempDir())
			conn, _, err := authControl(addr, 1, echoDestination(t))
			if err != nil {
				t.Fatal(err)
			}
			defer conn.Close()
			if _, err = conn.Write([]byte("PING\n")); err != nil {
				t.Fatal(err)
			}
			data := make([]byte, 5)
			if _, err = io.ReadFull(conn, data); err != nil {
				t.Fatal(err)
			}
			revoked := cloneSpec(n)
			revoked.FrontGate.TrustedClients = []string{}
			if err = b.Reload(revoked, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			conn.SetDeadline(time.Now().Add(time.Second))
			conn.Write([]byte("PING\n"))
			if _, err = io.ReadFull(conn, data); err == nil {
				t.Fatal("revoked established session remains usable")
			}
			// Invalid protected policies must stop the previous public listener, not roll back to it.
			invalid := cloneSpec(n)
			invalid.FrontGate.PrivateKey = "invalid"
			if err = b.Reload(invalid, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err == nil {
				t.Fatal("invalid identity accepted")
			}
			if b.IsRunning() {
				t.Fatal("listener left running after invalid protected policy")
			}
		})
	}
}
