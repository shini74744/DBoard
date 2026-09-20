package model

func RouteSupportMatrix() map[string]KernelRouteSupport {
	return map[string]KernelRouteSupport{
		"xray": {
			Matchers: []string{
				"domains",
				"domain_suffixes",
				"ip_cidrs",
				"ports",
				"networks",
				"protocols",
				"source_cidrs",
				"source_ports",
			},
			Actions: []string{"block", "direct", "route", "balancer"},
		},
		"singbox": {
			Matchers: []string{
				"domains",
				"domain_suffixes",
				"ip_cidrs",
				"ports",
				"networks",
				"protocols",
				"source_cidrs",
				"source_ports",
			},
			Actions: []string{"block", "direct", "route", "balancer"},
		},
	}
}

type KernelRouteSupport struct {
	Matchers []string
	Actions  []string
}
