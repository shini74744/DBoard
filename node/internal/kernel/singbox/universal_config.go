package singbox

import "github.com/shini74744/DBoard/node/internal/model"

func mapList(v any) []M {
	switch x := v.(type) {
	case []M:
		return x
	case []any:
		out := make([]M, 0, len(x))
		for _, v := range x {
			if m, ok := v.(map[string]any); ok {
				out = append(out, m)
			}
		}
		return out
	}
	return nil
}

func finishUniversalSingboxConfig(cfg M, nc *model.NodeSpec) {
	// Translate removed legacy block outbounds into the native reject action.
	// A removed block default becomes a terminal reject, never a direct fallback.
	blocks := map[string]bool{}
	outbounds := []M{}
	for _, ob := range mapList(cfg["outbounds"]) {
		if ob["type"] == "block" {
			tag, _ := ob["tag"].(string)
			blocks[tag] = true
			continue
		}
		outbounds = append(outbounds, ob)
	}
	cfg["outbounds"] = outbounds
	var rewrite func(M)
	rewrite = func(rule M) {
		if tag, ok := rule["outbound"].(string); ok && blocks[tag] {
			delete(rule, "outbound")
			rule["action"] = "reject"
		}
		for _, child := range mapList(rule["rules"]) {
			rewrite(child)
		}
	}
	route, _ := cfg["route"].(map[string]any)
	if route == nil {
		route = M{}
		cfg["route"] = route
	}
	rules := mapList(route["rules"])
	for _, rule := range rules {
		rewrite(rule)
	}
	if tag, ok := route["final"].(string); ok && blocks[tag] {
		delete(route, "final")
		rules = append(rules, M{"action": "reject"})
	}
	sniff := false
	for _, r := range nc.CustomRouteRules {
		if r.Disabled {
			continue
		}
		if len(r.Match.Domains)+len(r.Match.DomainSuffixes)+len(r.Match.Protocols) > 0 {
			sniff = true
		}
	}
	prefix := []M{}
	if sniff {
		prefix = append(prefix, M{"action": "sniff", "sniffer": []string{"http", "tls", "quic", "bittorrent"}, "timeout": "300ms"})
	}
	route["rules"] = append(prefix, rules...)
	dns, _ := cfg["dns"].(map[string]any)
	if dns == nil {
		dns = M{"servers": []M{{"type": "local", "tag": "__xboard_system_dns", "prefer_go": true}}}
		cfg["dns"] = dns
	}
	if _, ok := route["default_domain_resolver"]; !ok {
		servers := mapList(dns["servers"])
		if len(servers) > 0 {
			if tag, ok := servers[0]["tag"].(string); ok && tag != "" {
				route["default_domain_resolver"] = M{"server": tag}
			}
		}
	}
}
