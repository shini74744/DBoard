package probeagent

import (
	"os"
	"path/filepath"
	"testing"
)

func TestPreserveLegacyMachineCore(t *testing.T) {
	for _, layout := range []string{"root", "instances"} {
		for _, core := range []string{"singbox", "xray"} {
			t.Run(layout+"/"+core, func(t *testing.T) {
				item := "panel:\n  url: https://panel.example.test\nmachine:\n  machine_id: 27\n  token: test-token\nkernel:\n  type: " + core + "\n"
				if layout == "instances" {
					item = "instances:\n  - id: primary\n    panel:\n      url: https://panel.example.test\n    machine:\n      machine_id: 27\n      token: test-token\n    kernel:\n      type: " + core + "\n"
				}
				path := filepath.Join(t.TempDir(), "config.yml")
				os.WriteFile(path, []byte(item), 0600)
				got, err := LegacyKernel(path)
				if err != nil {
					t.Fatal(err)
				}
				if got != core {
					t.Fatalf("got %q", got)
				}
			})
		}
	}
}
