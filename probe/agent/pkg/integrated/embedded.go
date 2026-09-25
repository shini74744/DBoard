package integrated

import (
	"context"
	"errors"
	"github.com/nezhahq/agent/model"
	"sync/atomic"
)

var embeddedMode bool
var embeddedStarted atomic.Bool

// RunEmbedded runs the upstream monitoring runtime inside the node process.
// Its endpoint and upgrades are owned by the integrated supervisor.
func RunEmbedded(ctx context.Context, cfg model.AgentConfig) error {
	if !embeddedStarted.CompareAndSwap(false, true) {
		return errors.New("monitor runtime already started")
	}
	defer embeddedStarted.Store(false)
	cfg.DisableAutoUpdate = true
	cfg.DisableForceUpdate = true
	if err := model.ValidateConfig(&cfg, false); err != nil {
		return err
	}
	embeddedMode = true
	agentConfig = cfg
	publishRuntimeConfig(cfg)
	runContext(ctx)
	return nil
}

func UpdateEndpoint(server string, tls bool) {
	cfg := loadRuntimeConfig().Clone()
	cfg.Server = server
	cfg.TLS = tls
	publishRuntimeConfig(cfg)
	notifyReloadWorker()
}
