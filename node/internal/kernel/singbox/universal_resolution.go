package singbox

import (
	"github.com/shini74744/DBoard/node/internal/model"
	"strings"
)

// Resolve lazily at the IP-dependent rule, never before unrelated higher
// priority rules. Domain-OR may match before DNS, including domains resolved
// only by a remote exit. Common port/source/protocol guards also precede DNS.
func compileManagedRouteWithResolution(rule model.CustomRouteRule) []M {
	if rule.Disabled {
		return nil
	}
	if len(rule.Match.IPCIDRs) == 0 {
		return compileCustomRouteRule(rule)
	}
	result := []M{}
	hasDomain := len(rule.Match.Domains)+len(rule.Match.DomainSuffixes) > 0
	isAnd := strings.EqualFold(rule.IPDomainRelation, "and")
	if hasDomain && !isAnd {
		domainBranch := rule
		domainBranch.Match.IPCIDRs = nil
		result = append(result, compileCustomRouteRule(domainBranch)...)
	}
	guard := rule
	guard.Match.IPCIDRs = nil
	if !isAnd {
		guard.Match.Domains = nil
		guard.Match.DomainSuffixes = nil
	}
	resolve := compileCustomRouteRule(guard)[0]
	delete(resolve, "outbound")
	resolve["action"] = "resolve"
	result = append(result, resolve)
	result = append(result, compileCustomRouteRule(rule)...)
	return result
}
