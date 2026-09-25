// Package probemigrate performs the authenticated transition to the integrated Agent.
// It never executes shell text supplied by the panel.
package probemigrate

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/upgrade"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"syscall"
	"time"
)

//go:embed install.sh
var installer []byte

const jobPath = "/etc/DBoard-node/probe-install.json"
const dataDir = "/var/lib/nezha-integrated-agent"

type Job struct {
	RequestID  string "json:\"request_id\""
	Version    string "json:\"version\""
	Endpoint   string "json:\"endpoint\""
	UUID       string "json:\"uuid\""
	Enrollment string "json:\"enrollment\""
}

var origin = regexp.MustCompile("^https://[A-Za-z0-9.-]+(:[0-9]+)?$")
var uuidPattern = regexp.MustCompile("^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$")
var codePattern = regexp.MustCompile("^[a-f0-9]{48}$")

func (j Job) Validate() error {
	if !upgrade.ValidRequest(j.RequestID, j.Version) || !origin.MatchString(j.Endpoint) || !uuidPattern.MatchString(j.UUID) || !codePattern.MatchString(j.Enrollment) {
		return errors.New("invalid probe installation request")
	}
	return nil
}
func Launch(j Job) error {
	if err := j.Validate(); err != nil {
		return err
	}
	root, err := config.LoadRoot("/etc/DBoard-node/config.yml")
	if err != nil {
		return errors.New("cannot read legacy configuration")
	}
	instances, err := root.NormalizeInstances()
	if err != nil || len(instances) != 1 || !instances[0].IsMachineMode() {
		return errors.New("automatic probe takeover requires exactly one machine instance")
	}
	if os.Geteuid() != 0 {
		return errors.New("probe installation requires root")
	}
	// A single worker owns this immutable job. Never overwrite an active request.
	b, _ := json.Marshal(j)
	f, err := os.OpenFile(jobPath, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return errors.New("probe installation already queued; inspect installation status")
	}
	if _, err = f.Write(b); err == nil {
		err = f.Sync()
	}
	f.Close()
	if err != nil {
		os.Remove(jobPath)
		return err
	}
	exe, err := os.Executable()
	if err != nil {
		os.Remove(jobPath)
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	err = exec.CommandContext(ctx, "systemd-run", "--unit=nezha-integrated-agent-install", "--collect", "--no-block", "--property=RuntimeMaxSec=20min", exe, "--probe-install-worker").Run()
	if err != nil {
		os.Remove(jobPath)
	}
	return err
}
func Worker() (err error) {
	b, err := os.ReadFile(jobPath)
	if err != nil {
		return err
	}
	var j Job
	if err = json.Unmarshal(b, &j); err != nil {
		return err
	}
	if err = j.Validate(); err != nil {
		return err
	}
	defer os.Remove(jobPath)
	if err = os.MkdirAll(dataDir, 0700); err != nil {
		return err
	}
	lock, err := os.OpenFile(filepath.Join(dataDir, "worker.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return err
	}
	defer lock.Close()
	if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return err
	}
	report := func(state, msg string) {
		s := upgrade.Status{RequestID: j.RequestID, Version: j.Version, State: state, Message: msg}
		_ = upgrade.WriteStatus(upgrade.StatusPath, s)
		_ = upgrade.WriteStatus(filepath.Join(dataDir, "upgrade-status.json"), s)
	}
	report("downloading", "正在安装整合探针，保留原版哪吒")
	defer func() {
		if err != nil {
			report("failed", "探针安装失败，已执行原节点恢复；请查看安装日志")
		}
	}()
	f, err := os.CreateTemp(dataDir, "install-*.sh")
	if err != nil {
		return err
	}
	path := f.Name()
	defer os.Remove(path)
	_, err = f.Write(installer)
	f.Close()
	if err != nil {
		return err
	}
	log, err := os.OpenFile(filepath.Join(dataDir, "install.log"), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	defer log.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 18*time.Minute)
	defer cancel()
	cmd := exec.CommandContext(ctx, "bash", path, "--endpoint", j.Endpoint, "--uuid", j.UUID, "--enrollment", j.Enrollment, "--version", j.Version, "--takeover")
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error { return syscall.Kill(-cmd.Process.Pid, syscall.SIGTERM) }
	cmd.WaitDelay = 45 * time.Second
	cmd.Stdout = log
	cmd.Stderr = log
	if err = cmd.Run(); err != nil {
		return err
	}
	report("success", "整合探针已连接，原版哪吒保持运行")
	return nil
}
