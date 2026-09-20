package xray

import (
	"testing"

	"github.com/shini74744/DBoard/node/internal/model"
)

func TestStructuredProxyOutboundToXray(t *testing.T) {
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
					"alter_id":    0,
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
				if got["protocol"] != "vmess" || got["tag"] != "vmess-out" {
					t.Fatalf("unexpected base outbound: %#v", got)
				}
				stream := got["streamSettings"].(M)
				if stream["network"] != "ws" || stream["security"] != "tls" {
					t.Fatalf("unexpected stream: %#v", stream)
				}
				ws := stream["wsSettings"].(M)
				if ws["path"] != "/ws" {
					t.Fatalf("unexpected ws: %#v", ws)
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
				stream := got["streamSettings"].(M)
				if stream["security"] != "reality" {
					t.Fatalf("unexpected stream: %#v", stream)
				}
				reality := stream["realitySettings"].(M)
				if reality["publicKey"] != "public-key" || reality["shortId"] != "abcd" {
					t.Fatalf("unexpected reality: %#v", reality)
				}
			},
		},
		{
			name: "trojan ws tls",
			outbound: model.OutboundConfig{
				Tag:      "trojan-out",
				Protocol: "trojan",
				Settings: map[string]any{
					"server":      "trojan.example.com",
					"server_port": 443,
					"password":    "secret",
					"network":     "ws",
					"tls_mode":    "tls",
					"server_name": "trojan.example.com",
					"path":        "/tr",
				},
			},
			check: func(t *testing.T, got M) {
				if got["protocol"] != "trojan" {
					t.Fatalf("unexpected protocol: %#v", got)
				}
				stream := got["streamSettings"].(M)
				if stream["network"] != "ws" || stream["security"] != "tls" {
					t.Fatalf("unexpected stream: %#v", stream)
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
				settings := got["settings"].(M)
				servers := settings["servers"].([]M)
				if servers[0]["method"] != "aes-256-gcm" {
					t.Fatalf("unexpected ss settings: %#v", settings)
				}
			},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, ok := structuredProxyOutboundToXray(tc.outbound)
			if !ok {
				t.Fatal("conversion unexpectedly failed")
			}
			tc.check(t, got)
		})
	}
}
