package singbox

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/shini74744/DBoard/node/internal/model"
)

// structuredProxyOutboundToSingbox converts normalized share-link settings
// emitted by the custom Xboard panel into sing-box native outbound fields.
func structuredProxyOutboundToSingbox(oc model.OutboundConfig) (M, bool) {
	protocol := strings.ToLower(strings.TrimSpace(oc.Protocol))
	if protocol != "vmess" && protocol != "vless" && protocol != "trojan" && protocol != "shadowsocks" && protocol != "socks" && protocol != "http" {
		return nil, false
	}

	server := sbStringValue(oc.Settings["server"])
	port := sbIntValue(oc.Settings["server_port"])
	if server == "" || port <= 0 {
		return nil, false
	}

	out := M{
		"type":        protocol,
		"tag":         oc.Tag,
		"server":      server,
		"server_port": port,
	}

	switch protocol {
	case "socks", "http":
		for _, key := range []string{"username", "password"} {
			if v, ok := oc.Settings[key].(string); ok && v != "" {
				out[key] = v
			}
		}
		if protocol == "socks" {
			out["version"] = "5"
		}

	case "vmess":
		uuid := sbStringValue(oc.Settings["uuid"])
		if uuid == "" {
			return nil, false
		}
		out["uuid"] = uuid
		out["security"] = sbDefaultString(sbStringValue(oc.Settings["security"]), "auto")
		if alterID := sbIntValue(oc.Settings["alter_id"]); alterID > 0 {
			out["alter_id"] = alterID
		}
	case "vless":
		uuid := sbStringValue(oc.Settings["uuid"])
		if uuid == "" {
			return nil, false
		}
		out["uuid"] = uuid
		if flow := sbStringValue(oc.Settings["flow"]); flow != "" {
			out["flow"] = flow
		}
	case "trojan":
		password := rawCredential(oc.Settings["password"])
		if password == "" {
			return nil, false
		}
		out["password"] = password
	case "shadowsocks":
		method := sbFirstNonEmpty(
			sbStringValue(oc.Settings["method"]),
			sbStringValue(oc.Settings["cipher"]),
		)
		password := rawCredential(oc.Settings["password"])
		if method == "" || password == "" {
			return nil, false
		}
		out["method"] = method
		out["password"] = password
		if plugin := sbStringValue(oc.Settings["plugin"]); plugin != "" {
			out["plugin"] = plugin
		}
		if pluginOpts := sbStringValue(oc.Settings["plugin_opts"]); pluginOpts != "" {
			out["plugin_opts"] = pluginOpts
		}
	}

	if transport := singboxOutboundTransport(oc.Settings); len(transport) > 0 {
		out["transport"] = transport
	}
	if tls := singboxOutboundTLS(oc.Settings); len(tls) > 0 {
		out["tls"] = tls
	}

	if oc.ProxyTag != "" {
		// sing-box native chaining field.
		out["detour"] = oc.ProxyTag
	}

	return out, true
}

func singboxOutboundTransport(settings map[string]any) M {
	network := strings.ToLower(sbDefaultString(sbStringValue(settings["network"]), "tcp"))
	switch network {
	case "ws":
		transport := M{"type": "ws"}
		if path := sbStringValue(settings["path"]); path != "" {
			transport["path"] = path
		}
		if host := sbStringValue(settings["host"]); host != "" {
			transport["headers"] = M{"Host": host}
		}
		return transport
	case "grpc":
		transport := M{"type": "grpc"}
		if serviceName := sbFirstNonEmpty(
			sbStringValue(settings["service_name"]),
			sbStringValue(settings["serviceName"]),
		); serviceName != "" {
			transport["service_name"] = serviceName
		}
		return transport
	case "httpupgrade":
		transport := M{"type": "httpupgrade"}
		if path := sbStringValue(settings["path"]); path != "" {
			transport["path"] = path
		}
		if host := sbStringValue(settings["host"]); host != "" {
			transport["host"] = host
		}
		return transport
	default:
		return nil
	}
}

func singboxOutboundTLS(settings map[string]any) M {
	mode := strings.ToLower(sbStringValue(settings["tls_mode"]))
	if mode != "tls" && mode != "reality" {
		return nil
	}

	tls := M{"enabled": true}
	if version := sbIntValue(settings["front_gate_version"]); version == 1 {
		return M{"enabled": true, "server_name": sbStringValue(settings["server_name"]), "min_version": "1.3",
			"certificate":        []string{sbStringValue(settings["certificate_pem"])},
			"client_certificate": []string{sbStringValue(settings["client_certificate_pem"])},
			"client_key":         []string{rawCredential(settings["client_key_pem"])},
		}
	}
	if pem, ok := settings["certificate_pem"].(string); ok && pem != "" {
		tls["certificate"] = []string{pem}
	}
	if serverName := sbFirstNonEmpty(
		sbStringValue(settings["server_name"]),
		sbStringValue(settings["sni"]),
	); serverName != "" {
		tls["server_name"] = serverName
	}
	if alpn := sbStringSlice(settings["alpn"]); len(alpn) > 0 {
		tls["alpn"] = alpn
	}
	if fingerprint := sbStringValue(settings["fingerprint"]); fingerprint != "" {
		tls["utls"] = M{
			"enabled":     true,
			"fingerprint": fingerprint,
		}
	}

	if mode == "reality" {
		reality := M{"enabled": true}
		if publicKey := sbFirstNonEmpty(
			sbStringValue(settings["public_key"]),
			sbStringValue(settings["publicKey"]),
		); publicKey != "" {
			reality["public_key"] = publicKey
		}
		if shortID := sbFirstNonEmpty(
			sbStringValue(settings["short_id"]),
			sbStringValue(settings["shortId"]),
		); shortID != "" {
			reality["short_id"] = shortID
		}
		tls["reality"] = reality
	}

	return tls
}

func sbStringValue(value any) string {
	switch v := value.(type) {
	case string:
		return strings.TrimSpace(v)
	case fmt.Stringer:
		return strings.TrimSpace(v.String())
	default:
		if value == nil {
			return ""
		}
		return strings.TrimSpace(fmt.Sprint(value))
	}
}

func sbIntValue(value any) int {
	switch v := value.(type) {
	case int:
		return v
	case int32:
		return int(v)
	case int64:
		return int(v)
	case float64:
		return int(v)
	case float32:
		return int(v)
	case string:
		n, _ := strconv.Atoi(strings.TrimSpace(v))
		return n
	default:
		return 0
	}
}

func sbStringSlice(value any) []string {
	switch v := value.(type) {
	case []string:
		return v
	case []any:
		out := make([]string, 0, len(v))
		for _, item := range v {
			if s := sbStringValue(item); s != "" {
				out = append(out, s)
			}
		}
		return out
	case string:
		if strings.TrimSpace(v) == "" {
			return nil
		}
		parts := strings.Split(v, ",")
		out := make([]string, 0, len(parts))
		for _, part := range parts {
			if part = strings.TrimSpace(part); part != "" {
				out = append(out, part)
			}
		}
		return out
	default:
		return nil
	}
}

func sbDefaultString(value, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}

func sbFirstNonEmpty(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return value
		}
	}
	return ""
}

// Passwords are opaque protocol bytes. Do not trim legitimate whitespace.
func rawCredential(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}
