package bridge

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

func TestAdminEntryBoundary(t *testing.T) {
	a := NewAdminGate()
	now := time.Now()
	a.now = func() time.Time { return now }
	key := strings.Repeat("k", 48)
	next := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "" || r.URL.Query().Get("token") != "" {
			t.Error("guest credentials leaked")
		}
		w.WriteHeader(204)
	})
	h := (&Gateway{AdminGate: a, ControlKey: key}).Handler(next)
	call := func(method, path, body, bearer string, cookie *http.Cookie) *httptest.ResponseRecorder {
		r := httptest.NewRequest(method, "https://probe.test"+path, strings.NewReader(body))
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		if bearer != "" {
			r.Header.Set("Authorization", "Bearer "+bearer)
		}
		if cookie != nil {
			r.AddCookie(cookie)
		}
		w := httptest.NewRecorder()
		h.ServeHTTP(w, r)
		return w
	}
	for _, p := range []string{"/dashboard", "/dashboard/", "/dashboard/login", "/dashboard/assets/index.js", "/api/v1/login", "/api/v1/server", "/api/v1/profile", "/api/v1/oauth2/callback", "/api/v1/refresh-token", "/api/v1/ws/terminal/1", "/mcp", "/debug/pprof/", "/login", "/admin", "//dashboard/", "/assets/../dashboard/", "/%64ashboard/"} {
		if w := call("GET", p, "", "old-jwt", nil); w.Code != 404 {
			t.Errorf("%s: %d", p, w.Code)
		}
	}
	if w := call("POST", "/api/v1/login", "username=admin&password=test", "", nil); w.Code != 404 {
		t.Fatal(w.Code)
	}
	for _, p := range []string{"/", "/server/12", "/assets/index.js", "/api/v1/setting?token=legacy", "/api/v1/ws/server", "/api/v1/server/1/metrics"} {
		if w := call("GET", p, "", "old-jwt", &http.Cookie{Name: "nz-jwt", Value: "old"}); w.Code != 204 {
			t.Errorf("public %s: %d", p, w.Code)
		}
	}
	if w := call("POST", "/bridge/v1/control/admin-entry", "", "", nil); w.Code != 401 {
		t.Fatal(w.Code)
	}
	if w := call("POST", "/bridge/v1/admin/enter", "grant="+strings.Repeat("0", 64), "", nil); w.Code != 404 {
		t.Fatal(w.Code)
	}
	mint := func() string {
		w := call("POST", "/bridge/v1/control/admin-entry", "", key, nil)
		if w.Code != 200 {
			t.Fatal(w.Code)
		}
		var v struct{ Grant string }
		if err := json.Unmarshal(w.Body.Bytes(), &v); err != nil {
			t.Fatal(err)
		}
		return v.Grant
	}
	token := mint()
	if w := call("GET", "/bridge/v1/admin/enter?grant="+token, "", "", nil); w.Code != 404 {
		t.Fatal("GET consumed entry")
	}
	w := call("POST", "/bridge/v1/admin/enter", "grant="+url.QueryEscape(token), "", nil)
	if w.Code != 303 || w.Header().Get("Location") != "/dashboard/" {
		t.Fatal(w.Code)
	}
	cookie := w.Result().Cookies()[0]
	if cookie.Name != adminCookie || !cookie.Secure || !cookie.HttpOnly || cookie.Path != "/" || cookie.Domain != "" || cookie.SameSite != http.SameSiteLaxMode {
		t.Fatal("unsafe cookie")
	}
	if w := call("POST", "/bridge/v1/admin/enter", "grant="+token, "", nil); w.Code != 404 {
		t.Fatal("grant replay")
	}
	if w := call("GET", "/dashboard/", "", "", cookie); w.Code != 204 {
		t.Fatal("valid session denied")
	}
	if w := call("POST", "/api/v1/login", "", "", cookie); w.Code != 204 {
		t.Fatal("gated login denied")
	}
	expired := mint()
	now = now.Add(entryLifetime)
	if w := call("POST", "/bridge/v1/admin/enter", "grant="+expired, "", nil); w.Code != 404 {
		t.Fatal("expired grant accepted")
	}
	now = now.Add(adminLifetime)
	if w := call("GET", "/dashboard/", "", "", cookie); w.Code != 404 {
		t.Fatal("expired session accepted")
	}
}
