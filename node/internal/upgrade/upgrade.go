package upgrade

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"regexp"
)

var requestPattern = regexp.MustCompile(`^[a-f0-9]{16,32}$`)
var versionPattern = regexp.MustCompile(`^v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$`)

// Run runs the fixed, local upgrader in a separate systemd unit. The node
// service can restart without terminating the upgrade process.
func Run(requestID, version string) error {
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
	command := exec.Command("systemd-run", "--unit=dboard-node-upgrade", "--collect", "--wait", "--service-type=exec", "/usr/local/bin/xbctl", "upgrade", "--version", version)
	if output, err := command.CombinedOutput(); err != nil {
		return fmt.Errorf("launch upgrade: %w: %s", err, output)
	}
	return nil
}
