package model

import (
	"reflect"
	"testing"

	"github.com/shini74744/DBoard/node/internal/panel"
)

func TestRouteUserIDsSurvivePanelConversion(t *testing.T) {
	source := &panel.NodeConfig{CustomRouteRules: []panel.CustomRouteRule{{
		Match:  panel.RouteMatch{UserIDs: []int{11, 22}, DomainSuffixes: []string{"example.com"}},
		Action: panel.RouteAction{Type: "route", Target: "exit-b"},
	}}}
	spec := NodeSpecFromPanel(source)
	if got := spec.CustomRouteRules[0].Match.UserIDs; !reflect.DeepEqual(got, []int{11, 22}) {
		t.Fatalf("panel to node lost user IDs: %#v", got)
	}
	spec.CustomRouteRules[0].Match.UserIDs[0] = 33
	if source.CustomRouteRules[0].Match.UserIDs[0] != 11 {
		t.Fatal("panel user IDs were aliased")
	}
	if got := spec.ToPanel().CustomRouteRules[0].Match.UserIDs; !reflect.DeepEqual(got, []int{33, 22}) {
		t.Fatalf("node to panel lost user IDs: %#v", got)
	}
}
