package xray

import (
	"fmt"
	"github.com/shini74744/DBoard/node/internal/kernel"
	"github.com/shini74744/DBoard/node/internal/model"
	xrayCore "github.com/xtls/xray-core/core"
	"github.com/xtls/xray-core/features/stats"
)

type outboundStatsSource struct {
	manager stats.Manager
	ids     map[string]int
}

// Call with x.mu held. Retired instances remain readable while connections drain.
func (x *Xray) registerOutboundStats(inst *xrayCore.Instance, nc *model.NodeSpec) {
	manager, ok := inst.GetFeature(stats.ManagerType()).(stats.Manager)
	if !ok {
		return
	}
	if x.outboundSources == nil {
		x.outboundSources = make(map[*xrayCore.Instance]outboundStatsSource)
	}
	src := outboundStatsSource{manager: manager, ids: make(map[string]int)}
	for _, out := range nc.CustomOutbounds {
		if out.ID > 0 {
			src.ids[out.Tag] = out.ID
			x.outboundTraffic.Counter(out.ID)
		}
	}
	x.outboundSources[inst] = src
}
func (x *Xray) collectOutboundStats(src outboundStatsSource) {
	for tag, id := range src.ids {
		c := x.outboundTraffic.Counter(id)
		if v := src.manager.GetCounter(fmt.Sprintf("outbound>>>%s>>>traffic>>>uplink", tag)); v != nil {
			c.Upload.Add(v.Set(0))
		}
		if v := src.manager.GetCounter(fmt.Sprintf("outbound>>>%s>>>traffic>>>downlink", tag)); v != nil {
			c.Download.Add(v.Set(0))
		}
	}
}
func (x *Xray) finishOutboundStats(inst *xrayCore.Instance) {
	x.mu.Lock()
	defer x.mu.Unlock()
	if src, ok := x.outboundSources[inst]; ok {
		x.collectOutboundStats(src)
		delete(x.outboundSources, inst)
	}
}
func (x *Xray) GetOutboundTraffic() kernel.OutboundTrafficSnapshot {
	x.mu.Lock()
	defer x.mu.Unlock()
	for _, src := range x.outboundSources {
		x.collectOutboundStats(src)
	}
	return x.outboundTraffic.Snapshot()
}
