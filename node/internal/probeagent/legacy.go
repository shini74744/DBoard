package probeagent

import (
	"fmt"
	"github.com/shini74744/DBoard/node/internal/config"
	"gopkg.in/yaml.v3"
	"os"
)

// LegacyKernel reads only the core selection. A detached installer must not
// require or copy the legacy service's EnvironmentFile credentials.
func LegacyKernel(path string) (string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	var root config.RootConfig
	if err = yaml.Unmarshal(data, &root); err != nil {
		return "", err
	}
	if len(root.Instances) > 1 {
		return "", fmt.Errorf("takeover requires one machine instance")
	}
	selected := root.Config
	if len(root.Instances) == 1 {
		selected = root.Instances[0]
		if selected.Kernel.Type == "" {
			selected.Kernel.Type = root.Kernel.Type
		}
		if selected.Machine == nil {
			selected.Machine = root.Machine
		}
	}
	if selected.Machine == nil || selected.Machine.MachineID <= 0 {
		return "", fmt.Errorf("takeover requires machine mode")
	}
	core := selected.Kernel.Type
	if core == "" {
		core = "singbox"
	}
	if core != "singbox" && core != "xray" {
		return "", fmt.Errorf("invalid legacy core type")
	}
	return core, nil
}
