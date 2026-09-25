//go:build windows

package integrated

import "os/exec"

func killProcessGroupHard(cmd *exec.Cmd, _ int) {
	if cmd == nil || cmd.Process == nil {
		return
	}
	_ = cmd.Process.Kill()
}
