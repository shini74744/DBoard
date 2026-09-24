package upgrade

import (
	"strings"
	"testing"
)

func TestRunRejectsUntrustedCommandArguments(t *testing.T) {
	tests := []struct{ requestID, version string }{
		{"bad;id", "v0.1.5"},
		{"0123456789abcdef", "latest"},
		{"0123456789abcdef", "v0.1.5;touch /tmp/unsafe"},
		{"0123456789abcdef", "https://example.com/binary"},
	}
	for _, test := range tests {
		err := Run(test.requestID, test.version)
		if err == nil || !strings.Contains(err.Error(), "invalid upgrade request") {
			t.Errorf("Run(%q, %q) = %v, want invalid request", test.requestID, test.version, err)
		}
	}
}

func TestLauncherDetachesAndPreservesRequest(t *testing.T) {
	args := launchArgs("0123456789abcdef", "v0.1.8")
	joined := strings.Join(args, " ")
	if strings.Contains(joined, "--wait") || !strings.Contains(joined, "--no-block") || !strings.Contains(joined, "--request-id 0123456789abcdef") {
		t.Fatal(joined)
	}
}
