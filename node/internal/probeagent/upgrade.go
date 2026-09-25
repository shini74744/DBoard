package probeagent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"github.com/shini74744/DBoard/node/internal/upgrade"
	"github.com/shini74744/DBoard/probe/bridge"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

func LaunchUpgrade(configPath, id, version string) error {
	if !upgrade.ValidRequest(id, version) {
		return fmt.Errorf("invalid update request")
	}
	exe, e := os.Executable()
	if e != nil {
		return e
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	return exec.CommandContext(ctx, "systemd-run", "--unit=nezha-agent-update", "--collect", "--no-block", exe, "--upgrade-worker", "-c", configPath, "--version", version, "--request-id", id).Run()
}
func UpgradeWorker(c Config, configPath, version, id string) (err error) {
	if !upgrade.ValidRequest(id, version) {
		return fmt.Errorf("invalid update request")
	}
	statusPath := filepath.Join(c.DataDir, "upgrade-status.json")
	set := func(state, msg string) {
		_ = upgrade.WriteStatus(statusPath, upgrade.Status{RequestID: id, Version: version, State: state, Message: msg})
	}
	set("downloading", "")
	defer func() {
		if err != nil {
			set("failed", err.Error())
		}
	}()
	name := "nezha-agent-linux-" + runtime.GOARCH
	if runtime.GOOS != "linux" || (runtime.GOARCH != "amd64" && runtime.GOARCH != "arm64") {
		return fmt.Errorf("unsupported platform")
	}
	client := &http.Client{Timeout: 5 * time.Minute, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	get := func(file string) (*http.Response, error) {
		resp, e := client.Get(c.Endpoint + bridge.Prefix + "/artifacts/" + version + "/" + file)
		if e != nil {
			return nil, e
		}
		if resp.StatusCode != 200 {
			resp.Body.Close()
			return nil, fmt.Errorf("probe artifact unavailable")
		}
		return resp, nil
	}
	manifest, e := get("SHA256SUMS")
	if e != nil {
		return e
	}
	b, e := io.ReadAll(io.LimitReader(manifest.Body, 16384))
	manifest.Body.Close()
	if e != nil {
		return e
	}
	hash := ""
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && strings.TrimPrefix(f[1], "*") == name {
			hash = f[0]
		}
	}
	if len(hash) != 64 {
		return fmt.Errorf("missing artifact digest")
	}
	exe, e := os.Executable()
	if e != nil {
		return e
	}
	f, e := os.CreateTemp(filepath.Dir(exe), ".nezha-agent-update-*")
	if e != nil {
		return e
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	resp, e := get(name)
	if e != nil {
		f.Close()
		return e
	}
	h := sha256.New()
	n, e := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, 256<<20))
	resp.Body.Close()
	if e == nil {
		e = f.Sync()
	}
	f.Close()
	if e != nil {
		return e
	}
	if n == 0 || hex.EncodeToString(h.Sum(nil)) != hash {
		return fmt.Errorf("artifact digest mismatch")
	}
	if e = os.Chmod(tmp, 0755); e != nil {
		return e
	}
	check, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	out, e := exec.CommandContext(check, tmp, "-v").Output()
	cancel()
	if e != nil || !strings.Contains(string(out), "Integrated Nezha Agent "+version) {
		return fmt.Errorf("artifact is not the requested integrated Agent")
	}

	restart := func() error {
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		return exec.CommandContext(ctx, "systemctl", "restart", "nezha-agent.service").Run()
	}
	healthy := func(since int64) bool {
		deadline := time.Now().Add(90 * time.Second)
		for time.Now().Before(deadline) {
			if HealthySince(c, version, since) {
				return true
			}
			time.Sleep(time.Second)
		}
		return false
	}
	return swapAgent(exe, tmp, restart, healthy, set)
}

// The replace/restart transaction is independent from downloading so both success
// and rollback can be verified without touching a live systemd service.
func swapAgent(exe, candidate string, restart func() error, healthy func(int64) bool, set func(string, string)) error {
	backup := exe + ".previous"
	old, e := os.ReadFile(exe)
	if e != nil {
		return e
	}
	if e = atomicWrite(backup, old, 0755); e != nil {
		return e
	}
	if e = os.Rename(candidate, exe); e != nil {
		return e
	}
	started := time.Now().Unix()
	set("restarting", "")
	if e = restart(); e == nil {
		set("verifying", "等待新版本监控和节点通道恢复")
		if healthy(started) {
			set("success", "整合 Agent 已重新连接")
			return nil
		}
	}
	set("rolling_back", "新版本未通过连接检查，正在恢复")
	if e = os.Rename(backup, exe); e != nil {
		return fmt.Errorf("restore previous Agent: %w", e)
	}
	if e = restart(); e != nil {
		return fmt.Errorf("restart previous Agent: %w", e)
	}
	set("rolled_back", "新版本未通过连接检查，已恢复之前的程序")
	return nil
}
