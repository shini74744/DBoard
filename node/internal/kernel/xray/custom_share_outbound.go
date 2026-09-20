package xray

import (
	"fmt"
	"strconv"
	"strings"

	"github.com/shini74744/DBoard/node/internal/model"
)

// structuredProxyOutboundToXray converts the normalized share-link settings
// emitted by the custom Xboard panel into native Xray outbound JSON.
func structuredProxyOutboundToXray(oc model.OutboundConfig) (M, bool) {
	protocol := strings.ToLower(strings.TrimSpace(oc.Protocol))
	if protocol != "vmess" && protocol != "vless" && protocol != "trojan" && protocol != "shadowsocks" && protocol != "socks" && protocol != "http" {
		return nil, false
	}

	server := stringValue(oc.Settings["server"])
	port := intValue(oc.Settings["server_port"])
	if server == "" || port <= 0 {
		return nil, false
	}

	out := M{
		"protocol": protocol,
		"tag":      oc.Tag,
	}

	switch protocol {
	case "socks", "http":
		serverCfg := M{"address": server, "port": port}
		if username, ok := oc.Settings["username"].(string); ok && username != "" {
			password, _ := oc.Settings["password"].(string)
			serverCfg["users"] = []M{{"user": username, "pass": password}}
		}
		out["settings"] = M{"servers": []M{serverCfg}}

	case "vmess":
		id := stringValue(oc.Settings["uuid"])
		if id == "" {
			return nil, false
		}
		user := M{
			"id":      id,
			"alterId": intValue(oc.Settings["alter_id"]),
			"security": defaultString(
				stringValue(oc.Settings["security"]),
				"auto",
			),
		}
		out["settings"] = M{
			"vnext": []M{{
				"address": server,
				"port":    port,
				"users":   []M{user},
			}},
		}
	case "vless":
		id := stringValue(oc.Settings["uuid"])
		if id == "" {
			return nil, false
		}
		user := M{
			"id":         id,
			"encryption": defaultString(stringValue(oc.Settings["encryption"]), "none"),
		}
		if flow := stringValue(oc.Settings["flow"]); flow != "" {
			user["flow"] = flow
		}
		out["settings"] = M{
			"vnext": []M{{
				"address": server,
				"port":    port,
				"users":   []M{user},
			}},
		}
	case "trojan":
		password := rawCredential(oc.Settings["password"])
		if password == "" {
			return nil, false
		}
		out["settings"] = M{
			"servers": []M{{
				"address":  server,
				"port":     port,
				"password": password,
			}},
		}
	case "shadowsocks":
		method := firstNonEmpty(
			stringValue(oc.Settings["method"]),
			stringValue(oc.Settings["cipher"]),
		)
		password := rawCredential(oc.Settings["password"])
		if method == "" || password == "" {
			return nil, false
		}
		out["settings"] = M{
			"servers": []M{{
				"address":  server,
				"port":     port,
				"method":   method,
				"password": password,
			}},
		}
	}

	if stream := xrayOutboundStreamSettings(oc.Settings); len(stream) > 0 {
		out["streamSettings"] = stream
	}
	if oc.ProxyTag != "" {
		out["proxySettings"] = M{"tag": oc.ProxyTag}
	}
	return out, true
}

func xrayOutboundStreamSettings(settings map[string]any) M {
	stream := M{}
	network := strings.ToLower(defaultString(stringValue(settings["network"]), "tcp"))
	if network != "" {
		stream["network"] = network
	}

	switch network {
	case "ws":
		ws := M{}
		if path := stringValue(settings["path"]); path != "" {
			ws["path"] = path
		}
		if host := stringValue(settings["host"]); host != "" {
			ws["headers"] = M{"Host": host}
		}
		if len(ws) > 0 {
			stream["wsSettings"] = ws
		}
	case "grpc":
		grpc := M{}
		if serviceName := firstNonEmpty(
			stringValue(settings["service_name"]),
			stringValue(settings["serviceName"]),
		); serviceName != "" {
			grpc["serviceName"] = serviceName
		}
		if len(grpc) > 0 {
			stream["grpcSettings"] = grpc
		}
	case "httpupgrade":
		httpUpgrade := M{}
		if path := stringValue(settings["path"]); path != "" {
			httpUpgrade["path"] = path
		}
		if host := stringValue(settings["host"]); host != "" {
			httpUpgrade["host"] = host
		}
		if len(httpUpgrade) > 0 {
			stream["httpupgradeSettings"] = httpUpgrade
		}
	}

	tlsMode := strings.ToLower(stringValue(settings["tls_mode"]))
	serverName := firstNonEmpty(
		stringValue(settings["server_name"]),
		stringValue(settings["sni"]),
	)
	fingerprint := stringValue(settings["fingerprint"])
	alpn := stringSlice(settings["alpn"])

	switch tlsMode {
	case "tls":
		stream["security"] = "tls"
		tls := M{}
		if pem, ok := settings["certificate_pem"].(string); ok && pem != "" {
			tls["certificates"] = []M{{"certificate": strings.Split(pem, "\n"), "usage": "verify"}}
		}
		if serverName != "" {
			tls["serverName"] = serverName
		}
		if fingerprint != "" {
			tls["fingerprint"] = fingerprint
		}
		if len(alpn) > 0 {
			tls["alpn"] = alpn
		}
		if len(tls) > 0 {
			stream["tlsSettings"] = tls
		}
	case "reality":
		stream["security"] = "reality"
		reality := M{}
		if serverName != "" {
			reality["serverName"] = serverName
		}
		if fingerprint != "" {
			reality["fingerprint"] = fingerprint
		}
		if publicKey := firstNonEmpty(
			stringValue(settings["public_key"]),
			stringValue(settings["publicKey"]),
		); publicKey != "" {
			reality["publicKey"] = publicKey
		}
		if shortID := firstNonEmpty(
			stringValue(settings["short_id"]),
			stringValue(settings["shortId"]),
		); shortID != "" {
			reality["shortId"] = shortID
		}
		if spiderX := firstNonEmpty(
			stringValue(settings["spider_x"]),
			stringValue(settings["spiderX"]),
		); spiderX != "" {
			reality["spiderX"] = spiderX
		}
		stream["realitySettings"] = reality
	}

	return stream
}

func stringValue(value any) string {
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

func intValue(value any) int {
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

func stringSlice(value any) []string {
	switch v := value.(type) {
	case []string:
		return v
	case []any:
		out := make([]string, 0, len(v))
		for _, item := range v {
			if s := stringValue(item); s != "" {
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

func defaultString(value, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}

func firstNonEmpty(values ...string) string {
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
