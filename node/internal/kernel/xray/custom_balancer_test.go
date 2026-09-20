package xray

import (
	"testing"

	"github.com/shini74744/DBoard/node/internal/model"
)

func TestBuildXrayBalancers(t *testing.T) {
	items := []model.CustomBalancer{
		{
			Tag:         "asia",
			Strategy:    "leastPing",
			Selector:    []string{"hk", "jp"},
			FallbackTag: "hk",
		},
		{
			Tag:      "random-group",
			Strategy: "random",
			Selector: []string{"hk", "jp"},
		},
	}

	got := buildXrayBalancers(items)
	if len(got) != 2 {
		t.Fatalf("expected 2 balancers, got %d", len(got))
	}
	if got[0]["tag"] != "asia" || got[0]["fallbackTag"] != "hk" {
		t.Fatalf("unexpected leastPing balancer: %#v", got[0])
	}
	strategy, ok := got[0]["strategy"].(M)
	if !ok || strategy["type"] != "leastPing" {
		t.Fatalf("unexpected strategy: %#v", got[0]["strategy"])
	}
	if _, exists := got[1]["strategy"]; exists {
		t.Fatalf("random strategy should use Xray default and omit strategy: %#v", got[1])
	}
}

func TestApplyXrayBalancerObservatory(t *testing.T) {
	cfg := M{}
	items := []model.CustomBalancer{
		{Tag: "ping", Strategy: "leastPing", Selector: []string{"hk", "jp"}},
		{Tag: "load", Strategy: "leastLoad", Selector: []string{"us", "jp"}},
	}
	applyXrayBalancerObservatory(cfg, items)

	obs, ok := cfg["observatory"].(M)
	if !ok {
		t.Fatal("observatory missing")
	}
	subject := obs["subjectSelector"].([]string)
	if len(subject) != 2 || subject[0] != "hk" || subject[1] != "jp" {
		t.Fatalf("unexpected observatory selectors: %#v", subject)
	}

	burst, ok := cfg["burstObservatory"].(M)
	if !ok {
		t.Fatal("burstObservatory missing")
	}
	burstSubject := burst["subjectSelector"].([]string)
	if len(burstSubject) != 2 || burstSubject[0] != "jp" || burstSubject[1] != "us" {
		t.Fatalf("unexpected burst selectors: %#v", burstSubject)
	}
}

func TestCompileCustomRouteRuleBalancer(t *testing.T) {
	rules := compileCustomRouteRule(model.CustomRouteRule{Match: model.RouteMatch{DomainSuffixes: []string{"example.com"}}, Action: model.RouteAction{Type: "balancer", Target: "asia"}})
	if len(rules) != 1 || rules[0]["outboundTag"] != "asia" {
		t.Fatalf("managed group must route to shared runtime: %#v", rules)
	}
	if _, exists := rules[0]["balancerTag"]; exists {
		t.Fatalf("managed rule must not invoke native scheduler")
	}
}
