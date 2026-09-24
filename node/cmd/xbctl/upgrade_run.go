package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"github.com/shini74744/DBoard/node/internal/upgrade"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"
)

// Injection points keep failure-path tests away from live services and downloads.
var upgradeDownload = downloadWithRetry
var upgradeRestart = func() error {
	_, err := upgradeCommand(90*time.Second, "systemctl", "restart", serviceName)
	return err
}
var upgradeHealth = waitUpgradeHealthy
var upgradeLockPath = "/run/lock/dboard-node-upgrade.lock"
var upgradeStatusPath = upgrade.StatusPath

func runUpgrade(args []string) (result error) {
	if err := ensureRoot("upgrade"); err != nil {
		return err
	}
	target, requestID := "latest", ""
	for i := 0; i < len(args); i++ {
		switch args[i] {
		case "--version", "--request-id":
			if i+1 >= len(args) {
				return fmt.Errorf("missing value for %s", args[i])
			}
			key := args[i]
			i++
			if key == "--version" {
				target = args[i]
			} else {
				requestID = args[i]
			}
		default:
			return fmt.Errorf("unknown upgrade option: %s", args[i])
		}
	}
	if target != "latest" && !upgrade.ValidRequest("0123456789abcdef", target) {
		return errors.New("invalid release version")
	}
	if requestID != "" && !upgrade.ValidRequest(requestID, target) {
		return errors.New("invalid upgrade request")
	}
	if runtime.GOARCH != "amd64" && runtime.GOARCH != "arm64" {
		return errors.New("unsupported architecture")
	}
	lock, err := os.OpenFile(upgradeLockPath, os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return err
	}
	defer lock.Close()
	if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return errors.New("another upgrade is already running")
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
	rolledBack := false
	report := func(state, message string) {
		fmt.Println(message)
		if requestID != "" {
			if e := upgrade.WriteStatus(upgradeStatusPath, upgrade.Status{RequestID: requestID, Version: target, State: state, Message: message}); e != nil {
				fmt.Printf("write upgrade status: %v\n", e)
			}
		}
	}
	defer func() {
		if result != nil {
			state := "failed"
			if rolledBack {
				state = "rolled_back"
			}
			report(state, result.Error())
		}
	}()
	ctx, cancel := context.WithTimeout(context.Background(), 40*time.Minute)
	defer cancel()
	if target == "latest" {
		req, _ := http.NewRequestWithContext(ctx, "GET", "https://api.github.com/repos/shini74744/DBoard/releases/latest", nil)
		req.Header.Set("User-Agent", "DBoard-upgrader")
		client := &http.Client{Timeout: 30 * time.Second}
		resp, e := client.Do(req)
		if e != nil {
			return e
		}
		defer resp.Body.Close()
		if resp.StatusCode != 200 {
			return fmt.Errorf("GitHub release lookup: HTTP %d", resp.StatusCode)
		}
		var release struct {
			Tag string `json:"tag_name"`
		}
		if e = json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&release); e != nil {
			return e
		}
		if !upgrade.ValidRequest("0123456789abcdef", release.Tag) {
			return errors.New("invalid latest release")
		}
		target = release.Tag
	}
	stage, err := os.MkdirTemp(filepath.Dir(defaultBinaryPath), ".dboard-upgrade-")
	if err != nil {
		return err
	}
	keepBackup := false
	defer func() {
		if !keepBackup {
			os.RemoveAll(stage)
		}
	}()
	assetName := filepath.Base(defaultBinaryPath) + "-linux-" + runtime.GOARCH
	cliName := "xbctl-linux-" + runtime.GOARCH
	newBinary, newCLI := filepath.Join(stage, "node"), filepath.Join(stage, "xbctl")
	manifestPath := filepath.Join(stage, "SHA256SUMS")
	opt := slowDownloadOptions()
	opt.maxBytes = 1 << 20
	report("downloading", "正在读取版本校验文件，旧节点继续运行")
	if err = upgradeDownload(ctx, resolveDownloadURL("SHA256SUMS", target), manifestPath, opt); err != nil {
		return err
	}
	manifest, err := os.ReadFile(manifestPath)
	if err != nil {
		return err
	}
	for _, asset := range []struct{ name, path, label string }{{assetName, newBinary, "节点程序"}, {cliName, newCLI, "升级工具"}} {
		opt = slowDownloadOptions()
		label := asset.label
		opt.progress = func(done, total int64, attempt int) {
			report("downloading", downloadMessage(label, done, total, attempt))
		}
		if err = upgradeDownload(ctx, resolveDownloadURL(asset.name, target), asset.path, opt); err != nil {
			return err
		}
		report("validating", "正在校验"+label+"，旧节点继续运行")
		if err = verifyReleaseFile(asset.path, asset.name, manifest); err != nil {
			return err
		}
		if err = os.Chmod(asset.path, 0755); err != nil {
			return err
		}
	}
	if err = checkVersion(newBinary, "-v", target); err != nil {
		return err
	}
	if err = checkVersion(newCLI, "version", target); err != nil {
		return err
	}
	backupBinary, backupCLI := filepath.Join(stage, "node.backup"), filepath.Join(stage, "xbctl.backup")
	if err = copyUpgradeFile(defaultBinaryPath, backupBinary); err != nil {
		return fmt.Errorf("backup node: %w", err)
	}
	if err = copyUpgradeFile(defaultCLIPath, backupCLI); err != nil {
		return fmt.Errorf("backup xbctl: %w", err)
	}
	rollback := func(cause error) error {
		report("rolling_back", "新版本启动异常，正在恢复旧程序")
		if e := restoreUpgradeFiles(backupBinary, backupCLI, defaultBinaryPath, defaultCLIPath); e != nil {
			keepBackup = true
			return fmt.Errorf("rollback failed; backups retained at %s: %v (upgrade: %v)", stage, e, cause)
		}
		if e := upgradeRestart(); e != nil {
			keepBackup = true
			return fmt.Errorf("old binaries restored but restart failed; backups at %s: %w", stage, e)
		}
		if e := upgradeHealth(60 * time.Second); e != nil {
			keepBackup = true
			return fmt.Errorf("old binaries restored but health not confirmed; backups at %s: %w", stage, e)
		}
		rolledBack = true
		return fmt.Errorf("升级未完成，已恢复旧版本并确认服务运行：%v", cause)
	}
	if err = ctx.Err(); err != nil {
		return err
	}
	if err = os.Rename(newBinary, defaultBinaryPath); err != nil {
		return err
	}
	if err = os.Rename(newCLI, defaultCLIPath); err != nil {
		return rollback(err)
	}
	report("restarting", "下载及校验完成，正在重启节点")
	if err = upgradeRestart(); err != nil {
		return rollback(err)
	}
	report("verifying", "正在确认新版本持续运行")
	if err = upgradeHealth(90 * time.Second); err != nil {
		return rollback(err)
	}
	if err = checkVersion(defaultBinaryPath, "-v", target); err != nil {
		return rollback(err)
	}
	if root, e := loadWritableRootConfig(defaultConfigPath); e == nil {
		instances, _ := root.NormalizeInstances()
		writeInstallMetaVersioned(defaultMetaPath, root, target, latestInstanceID(instances))
	}
	report("success", "升级成功，已确认服务正常运行")
	return nil
}
func upgradeCommand(timeout time.Duration, name string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	return exec.CommandContext(ctx, name, args...).CombinedOutput()
}
func checkVersion(path, arg, target string) error {
	out, err := upgradeCommand(10*time.Second, path, arg)
	if err != nil {
		return fmt.Errorf("binary version check failed: %w", err)
	}
	fields := strings.Fields(string(out))
	if len(fields) < 2 || fields[1] != target {
		return fmt.Errorf("binary version mismatch: wanted %s", target)
	}
	return nil
}
func copyUpgradeFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(dst, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0755)
	if err != nil {
		return err
	}
	if _, err = io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	if err = out.Sync(); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}
func restoreUpgradeFiles(nodeBackup, cliBackup, nodePath, cliPath string) error {
	// Keep the backup copies until both restore and health checks complete.
	for _, pair := range [][2]string{{nodeBackup, nodePath}, {cliBackup, cliPath}} {
		temp := pair[0] + ".restore"
		if err := copyUpgradeFile(pair[0], temp); err != nil {
			return err
		}
		if err := os.Rename(temp, pair[1]); err != nil {
			return err
		}
	}
	return nil
}
func waitUpgradeHealthy(timeout time.Duration) error {
	return waitStableHealth(timeout, 2*time.Second, func() (string, error) {
		out, err := upgradeCommand(8*time.Second, "systemctl", "show", serviceName, "--property=ActiveState,SubState,MainPID,NRestarts")
		if err != nil {
			return "", err
		}
		fields := map[string]string{}
		for _, line := range strings.Split(string(out), "\n") {
			key, value, ok := strings.Cut(line, "=")
			if ok {
				fields[key] = value
			}
		}
		if fields["ActiveState"] != "active" || fields["SubState"] != "running" || fields["MainPID"] == "0" || fields["MainPID"] == "" {
			return "", errors.New("service is not running")
		}
		if instanceAwareHealth() == "down" {
			return "", errors.New("health endpoint not ready")
		}
		return fields["MainPID"] + ":" + fields["NRestarts"], nil
	})
}
func waitStableHealth(timeout, interval time.Duration, check func() (string, error)) error {
	deadline := time.Now().Add(timeout)
	stable := 0
	previous := ""
	var last error
	for time.Now().Before(deadline) {
		key, err := check()
		last = err
		if err == nil {
			if key == previous {
				stable++
			} else {
				previous = key
				stable = 1
			}
			if stable >= 3 {
				return nil
			}
		} else {
			stable = 0
			previous = ""
		}
		time.Sleep(interval)
	}
	if last == nil {
		last = errors.New("service repeatedly restarted")
	}
	return fmt.Errorf("health confirmation timed out: %w", last)
}
