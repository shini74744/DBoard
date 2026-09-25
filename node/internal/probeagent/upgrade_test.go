package probeagent

import (
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestUpgradeSwapSuccessAndRollback(t *testing.T) {
	for _, scenario := range []string{"success", "restart-fails", "health-fails"} {
		t.Run(scenario, func(t *testing.T) {
			dir := t.TempDir()
			exe := filepath.Join(dir, "agent")
			candidate := filepath.Join(dir, "candidate")
			os.WriteFile(exe, []byte("old"), 0755)
			os.WriteFile(candidate, []byte("new"), 0755)
			calls := 0
			last := ""
			restart := func() error {
				calls++
				if scenario == "restart-fails" && calls == 1 {
					return errors.New("restart rejected")
				}
				return nil
			}
			e := swapAgent(exe, candidate, restart, func(int64) bool { return scenario == "success" }, func(state, msg string) { last = state })
			if e != nil {
				t.Fatal(e)
			}
			actual, _ := os.ReadFile(exe)
			if scenario == "success" {
				if string(actual) != "new" || calls != 1 || last != "success" {
					t.Fatal("successful swap not committed")
				}
			} else {
				if string(actual) != "old" || calls != 2 || last != "rolled_back" {
					t.Fatal("failed upgrade not rolled back")
				}
			}
		})
	}
}
