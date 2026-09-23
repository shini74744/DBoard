package singbox

import (
	"github.com/shini74744/DBoard/node/internal/model"
	"strings"
)

// Resolve lazily at the IP-dependent rule, never before unrelated higher
// priority rules. Domain-OR may match before DNS, including domains resolved
// only by a remote exit. Common port/source/protocol guards also precede DNS.
func compileManagedRouteWithResolution(rule model.CustomRouteRule, users ...model.UserSpec) []M {
	if rule.Disabled {
		return nil
	}
	if len(rule.Match.IPCIDRs) == 0 {
		return compileCustomRouteRuleForUsers(rule, users)
	}
	result := []M{}
	hasDomain := len(rule.Match.Domains)+len(rule.Match.DomainSuffixes) > 0
	isAnd := strings.EqualFold(rule.IPDomainRelation, "and")
	if hasDomain && !isAnd {
		domainBranch := rule
		domainBranch.Match.IPCIDRs = nil
		result = append(result, compileCustomRouteRuleForUsers(domainBranch, users)...)
	}
	guard := rule
	guard.Match.IPCIDRs = nil
	if !isAnd {
		guard.Match.Domains = nil
		guard.Match.DomainSuffixes = nil
	}
	resolve := compileCustomRouteRuleForUsers(guard, users)[0]
	delete(resolve, "outbound")
	resolve["action"] = "resolve"
	result = append(result, resolve)
	result = append(result, compileCustomRouteRuleForUsers(rule, users)...)
	return result
}
