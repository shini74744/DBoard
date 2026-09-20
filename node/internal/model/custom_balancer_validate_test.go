package model

import (
	"strings"
	"testing"
)

func TestValidateCustomBalancers(t *testing.T) {
	outbounds := map[string]struct{}{
		"direct": {},
		"block":  {},
		"hk":     {},
		"jp":     {},
	}

	tests := []struct {
		name    string
		items   []CustomBalancer
		kernel  string
		wantErr string
	}{
		{
			name: "valid xray balancer",
			items: []CustomBalancer{{
				Tag:         "asia",
				Strategy:    "leastPing",
				Selector:    []string{"hk", "jp"},
				FallbackTag: "hk",
			}},
			kernel: "xray",
		},
		{
			name: "singbox supported",
			items: []CustomBalancer{{
				Tag: "asia", Strategy: "random", Selector: []string{"hk"},
			}},
			kernel:  "singbox",
			wantErr: "",
		},
		{
			name: "unknown selector",
			items: []CustomBalancer{{
				Tag: "asia", Strategy: "random", Selector: []string{"missing", "hk"},
			}},
			kernel:  "xray",
			wantErr: "unknown outbound",
		},
		{
			name: "duplicate tag",
			items: []CustomBalancer{
				{Tag: "asia", Strategy: "random", Selector: []string{"hk", "jp"}},
				{Tag: "asia", Strategy: "random", Selector: []string{"hk", "jp"}},
			},
			kernel:  "xray",
			wantErr: "duplicated",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			_, err := ValidateCustomBalancers(tc.items, tc.kernel, outbounds)
			if tc.wantErr == "" && err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if tc.wantErr != "" {
				if err == nil || !strings.Contains(err.Error(), tc.wantErr) {
					t.Fatalf("want error containing %q, got %v", tc.wantErr, err)
				}
			}
		})
	}
}
