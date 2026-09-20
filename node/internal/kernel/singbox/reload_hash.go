package singbox

import (
	"github.com/shini74744/DBoard/node/internal/kernel"
	"github.com/shini74744/DBoard/node/internal/model"
)

func inboundOnlyHash(n *model.NodeSpec, users []model.UserSpec) string {
	if n == nil {
		return ""
	}
	c := *n
	c.Routes = nil
	c.CustomRoutes = nil
	c.CustomRouteRules = nil
	c.CustomOutbounds = nil
	c.CustomBalancers = nil
	return kernel.ComputeHash(&c, users)
}
