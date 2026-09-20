package xray

import "github.com/shini74744/DBoard/node/internal/model"

func needsUniversalSniff(nc *model.NodeSpec) bool {
	for _, r := range nc.CustomRouteRules {
		if !r.Disabled && len(r.Match.Domains)+len(r.Match.DomainSuffixes)+len(r.Match.Protocols) > 0 {
			return true
		}
	}
	return false
}
