package probeagent

import (
	"fmt"
	"github.com/shini74744/DBoard/node/internal/config"
)

// LegacyKernel supports both the historical root layout and current instances layout.
// Automatic takeover must not silently drop additional panel instances.
func LegacyKernel(path string) (string, error) {
	root, err := config.LoadRoot(path)
	if err != nil {
		return "", err
	}
	instances, err := root.NormalizeInstances()
	if err != nil {
		return "", err
	}
	if len(instances) != 1 || !instances[0].IsMachineMode() {
		return "", fmt.Errorf("takeover requires one machine instance")
	}
	return instances[0].Kernel.Type, nil
}
