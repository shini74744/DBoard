package probemigrate

import (
	"bytes"
	"os"
	"strings"
	"testing"
)

func TestInstallerParity(t *testing.T) {
	b, e := os.ReadFile("../../../probe/install-agent.sh")
	if e != nil {
		t.Fatal(e)
	}
	if !bytes.Equal(b, installer) {
		t.Fatal("embedded installer differs from published installer")
	}
}
func TestJobRejectsUntrustedArguments(t *testing.T) {
	good := Job{RequestID: strings.Repeat("a", 24), Version: "v0.2.0", Endpoint: "https://probe.example.test", UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Enrollment: strings.Repeat("b", 48)}
	if e := good.Validate(); e != nil {
		t.Fatal(e)
	}
	for _, bad := range []string{"http://example.test", "https://example.test/x", "https://user:pw@example.test", "https://example.test;id", "https://example.test\nid", "https://example.test?x=1"} {
		j := good
		j.Endpoint = bad
		if j.Validate() == nil {
			t.Errorf("accepted %q", bad)
		}
	}
	j := good
	j.Version = "v0.2.0;id"
	if j.Validate() == nil {
		t.Fatal("accepted injected version")
	}
}
