package singbox

import (
	"testing"

	"github.com/shini74744/DBoard/node/internal/model"
)

func TestStructuredProxyOutboundToSingbox(t *testing.T) {
	tests := []struct {
		name     string
		outbound model.OutboundConfig
		check    func(t *testing.T, got M)
	}{
		{
			name: "vmess ws tls",
			outbound: model.OutboundConfig{
				Tag:      "vmess-out",
				Protocol: "vmess",
				Settings: map[string]any{
					"server":      "vmess.example.com",
					"server_port": 443,
					"uuid":        "11111111-1111-1111-1111-111111111111",
					"security":    "auto",
					"network":     "ws",
					"tls_mode":    "tls",
					"server_name": "vmess.example.com",
					"host":        "cdn.example.com",
					"path":        "/ws",
					"fingerprint": "chrome",
				},
			},
			check: func(t *testing.T, got M) {
				if got["type"] != "vmess" || got["uuid"] == "" {
					t.Fatalf("unexpected vmess: %#v", got)
				}
				transport := got["transport"].(M)
				if transport["type"] != "ws" || transport["path"] != "/ws" {
					t.Fatalf("unexpected transport: %#v", transport)
				}
				tls := got["tls"].(M)
				if tls["enabled"] != true || tls["server_name"] != "vmess.example.com" {
					t.Fatalf("unexpected tls: %#v", tls)
				}
			},
		},
		{
			name: "vless reality",
			outbound: model.OutboundConfig{
				Tag:      "vless-out",
				Protocol: "vless",
				Settings: map[string]any{
					"server":      "vless.example.com",
					"server_port": 443,
					"uuid":        "22222222-2222-2222-2222-222222222222",
					"network":     "tcp",
					"tls_mode":    "reality",
					"server_name": "www.example.com",
					"public_key":  "public-key",
					"short_id":    "abcd",
					"fingerprint": "chrome",
				},
			},
			check: func(t *testing.T, got M) {
				tls := got["tls"].(M)
				reality := tls["reality"].(M)
				if reality["enabled"] != true || reality["public_key"] != "public-key" || reality["short_id"] != "abcd" {
					t.Fatalf("unexpected reality: %#v", reality)
				}
			},
		},
		{
			name: "trojan grpc tls",
			outbound: model.OutboundConfig{
				Tag:      "trojan-out",
				Protocol: "trojan",
				Settings: map[string]any{
					"server":       "trojan.example.com",
					"server_port":  443,
					"password":     "secret",
					"network":      "grpc",
					"service_name": "tr-service",
					"tls_mode":     "tls",
					"server_name":  "trojan.example.com",
				},
			},
			check: func(t *testing.T, got M) {
				transport := got["transport"].(M)
				if transport["type"] != "grpc" || transport["service_name"] != "tr-service" {
					t.Fatalf("unexpected grpc transport: %#v", transport)
				}
			},
		},
		{
			name: "shadowsocks",
			outbound: model.OutboundConfig{
				Tag:      "ss-out",
				Protocol: "shadowsocks",
				Settings: map[string]any{
					"server":      "ss.example.com",
					"server_port": 8388,
					"method":      "aes-256-gcm",
					"password":    "secret",
				},
			},
			check: func(t *testing.T, got M) {
				if got["method"] != "aes-256-gcm" || got["password"] != "secret" {
					t.Fatalf("unexpected ss: %#v", got)
				}
			},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := structuredProxyOutboundToSingbox(tc.outbound)
			if !ok {
				t.Fatal("conversion unexpectedly failed")
			}
			tc.check(t, got)
		})
	}
}
