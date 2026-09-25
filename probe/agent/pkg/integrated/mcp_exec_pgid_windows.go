//go:build windows

package integrated

import "os/exec"

func processGroupID(_ *exec.Cmd) int { return 0 }
