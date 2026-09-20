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

	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	SB "github.com/shini74744/DBoard/node/internal/kernel/singbox"
	XR "github.com/shini74744/DBoard/node/internal/kernel/xray"
	"github.com/shini74744/DBoard/node/internal/model"
)

func selfSigned(t *testing.T) kernel.TLSCert {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "localhost"}, DNSNames: []string{"localhost"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
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
func echoDestination(t *testing.T) string {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			go func() { defer c.Close(); io.Copy(c, c) }()
		}
	}()
	return l.Addr().String()
}
func TestNormalizedProtocolMatrix(t *testing.T) {
	tests := []struct {
		protocol, transport string
		tls                 bool
	}{{"vmess", "tcp", false}, {"vless", "tcp", false}, {"shadowsocks", "tcp", false}, {"trojan", "tcp", true}, {"vmess", "ws", true}, {"vless", "grpc", true}}
	for _, core := range []string{"xray", "singbox"} {
		for _, tc := range tests {
			t.Run(core+"/"+tc.protocol+"/"+tc.transport, func(t *testing.T) {
				destination := echoDestination(t)
				// Each selected core talks to a real protocol server using the same normalized
				// share-link structure, including TLS/WS/gRPC rather than JSON-only assertions.
				kcfg := config.KernelConfig{Type: core, ConfigDir: t.TempDir(), LogLevel: "error"}
				var upstream kernel.Kernel
				if core == "xray" {
					upstream = XR.New(kcfg)
				} else {
					upstream = SB.New(kcfg)
				}
				u := &model.NodeSpec{Protocol: tc.protocol, ListenIP: "127.0.0.1", ServerPort: freePort(t), Network: tc.transport, Cipher: "aes-128-gcm", NetworkSettings: map[string]any{}, CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}}
				cert := kernel.TLSCert{}
				settings := map[string]any{"server": "127.0.0.1", "server_port": u.ServerPort, "network": tc.transport, "uuid": userID, "password": userID, "method": "aes-128-gcm", "security": "auto"}
				if tc.tls {
					u.TLS = 1
					cert = selfSigned(t)
					settings["tls_mode"] = "tls"
					settings["server_name"] = "localhost"
					settings["certificate_pem"] = string(cert.CertPEM)
				}
				if tc.transport == "ws" {
					u.NetworkSettings["path"] = "/test"
					settings["path"] = "/test"
				}
				if tc.transport == "grpc" {
					u.NetworkSettings["serviceName"] = "test"
					settings["service_name"] = "test"
				}
				if err := upstream.Start(u, []model.UserSpec{{ID: 1, UUID: userID}}, cert); err != nil {
					t.Fatal(err)
				}
				defer upstream.Stop()
				gateway := nodeWithExits(t)
				gateway.CustomOutbounds = []model.OutboundConfig{{Tag: "remote", Protocol: tc.protocol, Settings: settings}}
				gateway.CustomRouteRules = []model.CustomRouteRule{{Action: model.RouteAction{Type: "route", Target: "remote"}}}
				_, addr := launchSpec(t, core, gateway, t.TempDir())
				c, _, err := authControl(addr, 1, destination)
				if err != nil {
					t.Fatal(err)
				}
				defer c.Close()
				payload := fmt.Sprintf("protocol=%s transport=%s core=%s\n", tc.protocol, tc.transport, core)
				if _, err = c.Write([]byte(payload)); err != nil {
					t.Fatal(err)
				}
				buf := make([]byte, len(payload))
				if _, err = io.ReadFull(c, buf); err != nil {
					t.Fatal(err)
				}
				if string(buf) != payload {
					t.Fatalf("payload corrupted: %q", buf)
				}
			})
		}
	}
}
