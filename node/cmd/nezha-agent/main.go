package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	nzmodel "github.com/nezhahq/agent/model"
	nezha "github.com/nezhahq/agent/pkg/integrated"
	nzmonitor "github.com/nezhahq/agent/pkg/monitor"
	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/machine"
	"github.com/shini74744/DBoard/node/internal/panel"
	"github.com/shini74744/DBoard/node/internal/probeagent"
	"github.com/shini74744/DBoard/node/internal/upgrade"
	"github.com/shini74744/DBoard/probe/bridge"
)

var version = "v0.2.0-dev"

func main() {
	path := flag.String("c", "/etc/nezha-agent/config.json", "agent configuration")
	show := flag.Bool("v", false, "version")
	worker := flag.Bool("upgrade-worker", false, "run authenticated update worker")
	target := flag.String("version", "", "target version")
	requestID := flag.String("request-id", "", "upgrade request")
	legacyConfig := flag.String("legacy-config", "", "existing node config used to preserve core selection")
	enroll := flag.Bool("enroll", false, "enroll a scoped probe identity")
	endpoint := flag.String("endpoint", "", "probe HTTPS origin")
	uuid := flag.String("uuid", "", "probe device UUID")
	code := flag.String("enrollment", "", "one-time enrollment code")
	health := flag.Bool("health-check", false, "check integrated connectivity")
	since := flag.Int64("since", 0, "oldest acceptable health timestamp")
	flag.Parse()
	if *enroll {
		if e := probeagent.Enroll(context.Background(), *path, *endpoint, *uuid, *code); e != nil {
			fmt.Fprintln(os.Stderr, e)
			os.Exit(1)
		}
		if *legacyConfig != "" {
			old, e := config.Load(*legacyConfig)
			if e != nil {
				fmt.Fprintln(os.Stderr, "unable to read previous core settings")
				os.Exit(1)
			}
			enrolled, e := probeagent.Load(*path)
			if e != nil {
				fmt.Fprintln(os.Stderr, e)
				os.Exit(1)
			}
			enrolled.Kernel = old.Kernel.Type
			if e = enrolled.Save(*path); e != nil {
				fmt.Fprintln(os.Stderr, e)
				os.Exit(1)
			}
		}
		return
	}
	if *show {
		fmt.Println("Integrated Nezha Agent", version)
		return
	}
	cfg, e := probeagent.Load(*path)
	if e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
	if *health {
		if !probeagent.HealthySince(cfg, *target, *since) {
			os.Exit(1)
		}
		return
	}
	if *worker {
		if e = probeagent.UpgradeWorker(cfg, *path, *target, *requestID); e != nil {
			fmt.Fprintln(os.Stderr, e)
			os.Exit(1)
		}
		return
	}
	rt, e := probeagent.New(cfg, *path)
	if e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
	rt.MonitorHealthy = nezha.Healthy
	panel.NodeVersion = version
	nzmonitor.Version = version
	panel.ProbeTransport = rt
	panel.ProbeDial = rt.DialWS
	panel.ProbeForget = rt.ForgetWS
	panel.ProbeEvent = rt.Event
	panel.ProbeHealthy = func() { _ = probeagent.WriteHealth(rt.Config(), version, nezha.Healthy()) }
	rt.ValidateEndpoint = func(ctx context.Context, c probeagent.Config) error {
		if e := nezha.ValidateEndpoint(ctx, nzmodel.AgentConfig{Server: c.Server(), TLS: true, UUID: c.UUID, ClientSecret: c.Secret}); e != nil {
			return fmt.Errorf("monitor connection check: %w", e)
		}
		return rt.ValidateWebSocket(ctx, c)
	}
	upgrade.StatusPath = filepath.Join(cfg.DataDir, "upgrade-status.json")
	upgrade.ManagedRun = func(id, v string) error { return probeagent.LaunchUpgrade(*path, id, v) }
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	rt.OnEndpoint = func(c probeagent.Config) { nezha.UpdateEndpoint(c.Server(), strings.HasPrefix(c.Endpoint, "https://")) }
	go rt.Run(ctx)
	go rt.Watch(ctx)
	go func() {
		c := rt.Config()
		if e := nezha.RunEmbedded(ctx, nzmodel.AgentConfig{Server: c.Server(), ClientSecret: c.Secret, UUID: c.UUID, TLS: strings.HasPrefix(c.Endpoint, "https://"), ReportDelay: 3, DisableAutoUpdate: true, DisableForceUpdate: true}); e != nil {
			fmt.Fprintln(os.Stderr, e)
			cancel()
		}
	}()
	// Only probe origin and a device-scoped key enter the existing node engine.
	raw, _ := json.Marshal(map[string]any{"panel": map[string]any{"url": cfg.Endpoint + bridge.Prefix + "/node"}, "machine": map[string]any{"machine_id": 1, "token": cfg.Secret}, "kernel": map[string]any{"type": cfg.Kernel, "config_dir": filepath.Join(cfg.DataDir, "nodes")}, "log": map[string]any{"level": "info"}})
	engineFile := filepath.Join(cfg.DataDir, "engine.json")
	if e = os.WriteFile(engineFile, raw, 0600); e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
	os.Chmod(engineFile, 0600)
	engineCfg, e := config.Load(engineFile)
	if e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(1)
	}
	config.InitLogger(engineCfg.Log)
	for ctx.Err() == nil {
		if e = machine.New(engineCfg).Run(ctx); e != nil {
			fmt.Fprintln(os.Stderr, "probe node discovery temporarily unavailable")
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(5 * time.Second):
		}
	}
}
