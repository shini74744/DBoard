package controller

import (
	"strings"
	"testing"
)

func TestProbePublicFrontendHasNoAdminEntry(t *testing.T) {
	t.Chdir(t.TempDir())
	t.Setenv("NEZHA_BRIDGE_KEY_FILE", "enabled")
	router := newFrontendFallbackTestRouter(t)
	writeFrontendFallbackTestFile(t, "user-dist/index.html", "<html><head></head><body>monitor</body></html>")
	public := performFrontendFallbackRequest(t, router, "/")
	if public.Code != 200 || !strings.Contains(public.Body.String(), "probe-public-only") || !strings.Contains(public.Header().Get("Cache-Control"), "no-store") {
		t.Fatal("public entry removal missing")
	}
	admin := performFrontendFallbackRequest(t, router, "/dashboard/")
	if strings.Contains(admin.Body.String(), "probe-public-only") {
		t.Fatal("public patch applied to admin")
	}
}
