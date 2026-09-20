package integration_test

import (
	"crypto/ecdh"
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"fmt"
	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	SB "github.com/shini74744/DBoard/node/internal/kernel/singbox"
	XR "github.com/shini74744/DBoard/node/internal/kernel/xray"
	"github.com/shini74744/DBoard/node/internal/model"
	reality "github.com/xtls/reality"
	"io"
	"net"
	"testing"
	"time"
)

func anotherCore(t *testing.T, selected string) kernel.Kernel {
	cfg := config.KernelConfig{Type: selected, ConfigDir: t.TempDir(), LogLevel: "error"}
	if selected == "xray" {
		return XR.New(cfg)
	}
	return SB.New(cfg)
}
func testGatewayEcho(t *testing.T, core string, out model.OutboundConfig) {
	t.Helper()
	dest := echoDestination(t)
	n := nodeWithExits(t)
	n.CustomOutbounds = []model.OutboundConfig{out}
	n.CustomRouteRules = []model.CustomRouteRule{{Action: model.RouteAction{Type: "route", Target: out.Tag}}}
	_, addr := launchSpec(t, core, n, t.TempDir())
	c, _, err := authControl(addr, 1, dest)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	payload := []byte("authenticated-proxy-payload")
	if _, err = c.Write(payload); err != nil {
		t.Fatal(err)
	}
	got := make([]byte, len(payload))
	if _, err = io.ReadFull(c, got); err != nil {
		t.Fatal(err)
	}
	if string(got) != string(payload) {
		t.Fatal("echo mismatch")
	}
}
func TestSS2022Interoperability(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		for _, size := range []int{16, 32} {
			t.Run(fmt.Sprintf("%s/%d", core, size), func(t *testing.T) {
				peerCore := "xray"
				if core == peerCore {
					peerCore = "singbox"
				}
				peer := anotherCore(t, peerCore)
				key := make([]byte, size)
				if _, err := rand.Read(key); err != nil {
					t.Fatal(err)
				}
				serverKey := base64.StdEncoding.EncodeToString(key)
				userKey := make([]byte, size)
				copy(userKey, userID)
				method := fmt.Sprintf("2022-blake3-aes-%d-gcm", size*8)
				n := &model.NodeSpec{Protocol: "shadowsocks", ListenIP: "127.0.0.1", ServerPort: freePort(t), Cipher: method, ServerKey: serverKey, CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}}
				if err := peer.Start(n, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
					t.Fatal(err)
				}
				defer peer.Stop()
				testGatewayEcho(t, core, model.OutboundConfig{Tag: "remote", Protocol: "shadowsocks", Settings: map[string]any{"server": "127.0.0.1", "server_port": n.ServerPort, "method": method, "password": serverKey + ":" + base64.StdEncoding.EncodeToString(userKey)}})
			})
		}
	}
}
func TestRealityInteroperability(t *testing.T) {
	if !releaseUTLS {
		t.Skip("Reality requires release build tag with_utls")
	}
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			cert := selfSigned(t)
			pair, err := tls.X509KeyPair(cert.CertPEM, cert.KeyPEM)
			if err != nil {
				t.Fatal(err)
			}
			target, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{Certificates: []tls.Certificate{pair}, MinVersion: tls.VersionTLS13, CurvePreferences: []tls.CurveID{tls.X25519}, NextProtos: []string{"h2", "http/1.1"}})
			if err != nil {
				t.Fatal(err)
			}
			defer target.Close()
			go func() {
				for {
					c, err := target.Accept()
					if err != nil {
						return
					}
					go func(c net.Conn) {
						defer c.Close()
						c.SetDeadline(time.Now().Add(3 * time.Second))
						io.Copy(io.Discard, c)
					}(c)
				}
			}()
			secret, err := ecdh.X25519().GenerateKey(rand.Reader)
			if err != nil {
				t.Fatal(err)
			}
			peerCore := "xray"
			if core == peerCore {
				peerCore = "singbox"
			}
			peer := anotherCore(t, peerCore)
			n := &model.NodeSpec{Protocol: "vless", ListenIP: "127.0.0.1", ServerPort: freePort(t), Network: "tcp", TLS: 2, TLSSettings: map[string]any{"private_key": base64.RawURLEncoding.EncodeToString(secret.Bytes()), "short_id": "abcd", "server_name": "localhost", "dest": target.Addr().String()}, CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}}
			if err = peer.Start(n, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			defer peer.Stop()
			// REALITY's Xray server asynchronously measures the target's
			// post-handshake records. Wait for that fixture warmup instead of
			// racing the SOCKS test's intentionally short read deadline.
			if peerCore == "xray" {
				key := target.Addr().String() + " localhost 2"
				ready := false
				until := time.Now().Add(10 * time.Second)
				for time.Now().Before(until) {
					v, ok := reality.GlobalPostHandshakeRecordsLens.Load(key)
					if ok {
						if _, done := v.([]int); done {
							ready = true
							break
						}
					}
					time.Sleep(20 * time.Millisecond)
				}
				if !ready {
					t.Fatal("camouflage handshake probe did not become ready")
				}
			}
			testGatewayEcho(t, core, model.OutboundConfig{Tag: "remote", Protocol: "vless", Settings: map[string]any{"server": "127.0.0.1", "server_port": n.ServerPort, "uuid": userID, "network": "tcp", "tls_mode": "reality", "server_name": "localhost", "public_key": base64.RawURLEncoding.EncodeToString(secret.PublicKey().Bytes()), "short_id": "abcd", "fingerprint": "chrome"}})
		})
	}
}
