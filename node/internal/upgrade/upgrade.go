package upgrade

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"time"
)

var requestPattern = regexp.MustCompile(`^[a-f0-9]{16,32}$`)
var versionPattern = regexp.MustCompile(`^v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$`)

// Run runs the fixed, local upgrader in a separate systemd unit. The node
// service can restart without terminating the upgrade process.
var ManagedRun func(string, string) error

func Run(requestID, version string) error {
	if ManagedRun != nil {
		return ManagedRun(requestID, version)
	}
	if !requestPattern.MatchString(requestID) || !versionPattern.MatchString(version) {
		return errors.New("invalid upgrade request")
	}
	if os.Geteuid() != 0 {
		return errors.New("node service must run as root to upgrade")
	}
	for _, path := range []string{"/run/systemd/system", "/etc/systemd/system/DBoard-node.service", "/usr/local/bin/xbctl"} {
		if _, err := os.Stat(path); err != nil {
			return fmt.Errorf("remote upgrade unavailable: %s: %w", path, err)
		}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, "systemd-run", launchArgs(requestID, version)...)
	if output, err := command.CombinedOutput(); err != nil {
		return fmt.Errorf("launch upgrade: %w: %s", err, output)
	}
	return nil
}

// Do not wait for unit completion: restarting the node kills systemd-run's
// parent cgroup. The detached xbctl writes durable progress for the new node.
func launchArgs(id, version string) []string {
	return []string{"--unit=dboard-node-upgrade", "--collect", "--no-block", "--service-type=exec", "--property=RuntimeMaxSec=50min", "/usr/local/bin/xbctl", "upgrade", "--version", version, "--request-id", id}
}
