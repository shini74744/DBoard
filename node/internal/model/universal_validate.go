package model

import (
	"fmt"
	"net/netip"
	"regexp"
	"strings"
)

func validateUniversalRuleValues(rules []CustomRouteRule) error {
	geo := regexp.MustCompile(`^(geosite|geoip):[A-Za-z0-9_@.!+\-]+$`)
	for i, r := range rules {
		if r.Disabled {
			continue
		}
		relation := strings.ToLower(strings.TrimSpace(r.IPDomainRelation))
		if relation != "" && relation != "or" && relation != "and" {
			return fmt.Errorf("rule %d: invalid IP/domain relation", i)
		}
		for _, set := range [][]string{r.Match.Domains, r.Match.DomainSuffixes} {
			for _, raw := range set {
				v := strings.TrimSpace(raw)
				if v == "" {
					return fmt.Errorf("rule %d: blank domain entry", i)
				}
				if strings.HasPrefix(v, "geosite:") {
					if !geo.MatchString(v) {
						return fmt.Errorf("rule %d: invalid geosite reference", i)
					}
					continue
				}
				if strings.HasPrefix(v, "regexp:") {
					if _, err := regexp.Compile(strings.TrimPrefix(v, "regexp:")); err != nil {
						return fmt.Errorf("rule %d: invalid domain regex: %w", i, err)
					}
					continue
				}
				for _, prefix := range []string{"full:", "domain:", "keyword:"} {
					v = strings.TrimPrefix(v, prefix)
				}
				if v == "" || strings.ContainsAny(v, "\r\n\x00") {
					return fmt.Errorf("rule %d: invalid domain entry", i)
				}
			}
		}
		for _, set := range [][]string{r.Match.IPCIDRs, r.Match.SourceCIDRs} {
			for _, v := range set {
				if strings.HasPrefix(v, "geoip:") {
					if !geo.MatchString(v) {
						return fmt.Errorf("rule %d: invalid geoip reference", i)
					}
					continue
				}
				if _, err := netip.ParseAddr(v); err == nil {
					continue
				}
				if _, err := netip.ParsePrefix(v); err != nil {
					return fmt.Errorf("rule %d: invalid IP/CIDR %q", i, v)
				}
			}
		}
		for _, set := range [][]string{r.Match.Ports, r.Match.SourcePorts, r.Match.Networks, r.Match.Protocols} {
			for _, v := range set {
				if strings.TrimSpace(v) == "" {
					return fmt.Errorf("rule %d: blank matcher must not become a catch-all", i)
				}
			}
		}
	}
	return nil
}

// Explicitly catch multi-hop cycles before either core starts. A chain is not
// limited to the trivial a->a case and must never re-enter a balancer.
func validateUniversalReferences(n *NodeSpec, additional []string) error {
	exact := map[string]bool{"direct": true, "block": true}
	for _, tag := range additional {
		exact[tag] = true
	}
	graph := map[string][]string{}
	for _, o := range n.CustomOutbounds {
		exact[o.Tag] = true
		if o.ProxyTag != "" {
			graph[o.Tag] = []string{o.ProxyTag}
		}
	}
	for _, b := range n.CustomBalancers {
		exact[b.Tag] = true
		graph[b.Tag] = append(append([]string(nil), b.Selector...), b.FallbackTag)
	}
	for from, targets := range graph {
		for _, to := range targets {
			if to != "" && !exact[to] {
				return fmt.Errorf("%s references unknown or differently-cased tag %q", from, to)
			}
		}
	}
	color := map[string]int{}
	var visit func(string) error
	visit = func(tag string) error {
		if color[tag] == 1 {
			return fmt.Errorf("outbound/balancer reference cycle at %q", tag)
		}
		if color[tag] == 2 {
			return nil
		}
		color[tag] = 1
		for _, to := range graph[tag] {
			if to != "" {
				if err := visit(to); err != nil {
					return err
				}
			}
		}
		color[tag] = 2
		return nil
	}
	for tag := range graph {
		if err := visit(tag); err != nil {
			return err
		}
	}
	for _, r := range n.CustomRouteRules {
		if r.Disabled {
			continue
		}
		if (r.Action.Type == "route" || r.Action.Type == "balancer") && !exact[r.Action.Target] {
			return fmt.Errorf("unknown or differently-cased route target %q", r.Action.Target)
		}
	}
	return nil
}
