package model

import (
	"fmt"
	"github.com/shini74744/DBoard/node/internal/balance"
	"regexp"
	"strings"
)

func ValidateCustomBalancers(items []CustomBalancer, kernelType string, availableOutboundTags map[string]struct{}) (map[string]struct{}, error) {
	tags := map[string]struct{}{}
	if len(items) == 0 {
		return tags, nil
	}

	kernelType = strings.ToLower(strings.TrimSpace(kernelType))
	if kernelType != "xray" && kernelType != "singbox" && kernelType != "sing-box" {
		return nil, fmt.Errorf("unsupported balancer kernel %q", kernelType)
	}
	if len(items) > 128 {
		return nil, fmt.Errorf("too many balancer groups")
	}

	for i, item := range items {
		tag := strings.ToLower(strings.TrimSpace(item.Tag))
		if tag == "" {
			return nil, fmt.Errorf("custom_balancers[%d].tag is required", i)
		}
		if _, ok := tags[tag]; ok {
			return nil, fmt.Errorf("custom_balancers[%d].tag %q is duplicated", i, item.Tag)
		}
		if _, ok := availableOutboundTags[tag]; ok {
			return nil, fmt.Errorf("custom_balancers[%d].tag %q collides with an outbound tag", i, item.Tag)
		}

		if !regexp.MustCompile(`^[A-Za-z0-9._-]+$`).MatchString(item.Tag) {
			return nil, fmt.Errorf("invalid balancer tag %q", item.Tag)
		}
		if _, err := balance.NormalizeStrategy(item.Strategy); err != nil {
			return nil, err
		}
		if _, err := item.BalanceConfig().Normalize(); err != nil {
			return nil, fmt.Errorf("custom_balancers[%d]: %w", i, err)
		}
		// Preserve legacy one-member groups; the panel may still recommend two.
		if item.ProbeIntervalSeconds != 0 && (item.ProbeIntervalSeconds < 5 || item.ProbeIntervalSeconds > 3600) {
			return nil, fmt.Errorf("invalid probe interval")
		}
		if item.ProbeTimeoutSeconds != 0 && (item.ProbeTimeoutSeconds < 1 || item.ProbeTimeoutSeconds > 30) {
			return nil, fmt.Errorf("invalid probe timeout")
		}

		for _, selector := range item.Selector {
			selector = strings.ToLower(strings.TrimSpace(selector))
			if selector == "" || selector == "block" {
				return nil, fmt.Errorf("invalid balancer member %q", selector)
			}
			if _, ok := availableOutboundTags[selector]; !ok {
				return nil, fmt.Errorf("custom_balancers[%d].selector references unknown outbound %q", i, selector)
			}
		}

		if fallback := strings.ToLower(strings.TrimSpace(item.FallbackTag)); fallback != "" {
			if _, ok := availableOutboundTags[fallback]; !ok {
				return nil, fmt.Errorf("custom_balancers[%d].fallback_tag references unknown outbound %q", i, item.FallbackTag)
			}
		}

		tags[tag] = struct{}{}
	}

	return tags, nil
}
