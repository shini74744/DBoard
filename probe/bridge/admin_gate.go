package bridge

import (
	"crypto/rand"
	"encoding/hex"
	"net/http"
	"path"
	"regexp"
	"strings"
	"sync"
	"time"
)

const adminCookie = "__Host-probe-admin"
const entryLifetime = 60 * time.Second
const adminLifetime = 2 * time.Hour

// Entry grants are single use. Sessions intentionally disappear on restart.
type AdminGate struct {
	mu       sync.Mutex
	grants   map[string]time.Time
	sessions map[string]time.Time
	now      func() time.Time
}

func NewAdminGate() *AdminGate {
	return &AdminGate{grants: map[string]time.Time{}, sessions: map[string]time.Time{}, now: time.Now}
}
func randomAccessToken() string {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func (a *AdminGate) prune() {
	now := a.now()
	for k, v := range a.grants {
		if !now.Before(v) {
			delete(a.grants, k)
		}
	}
	for k, v := range a.sessions {
		if !now.Before(v) {
			delete(a.sessions, k)
		}
	}
}
func (a *AdminGate) Issue(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.NotFound(w, r)
		return
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	a.prune()
	if len(a.grants) >= 1024 || len(a.sessions) >= 1024 {
		http.Error(w, "busy", 503)
		return
	}
	token := randomAccessToken()
	a.grants[digest(token)] = a.now().Add(entryLifetime)
	w.Header().Set("Cache-Control", "no-store")
	writeJSON(w, 200, map[string]any{"grant": token, "expires_in": 60})
}
func (a *AdminGate) Enter(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Referrer-Policy", "no-referrer")
	if r.Method != http.MethodPost {
		http.NotFound(w, r)
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 2048)
	if r.ParseForm() != nil {
		http.NotFound(w, r)
		return
	}
	token := r.PostForm.Get("grant")
	a.mu.Lock()
	defer a.mu.Unlock()
	a.prune()
	expires, ok := a.grants[digest(token)]
	if len(token) != 64 || !ok || !a.now().Before(expires) || len(a.sessions) >= 1024 {
		http.NotFound(w, r)
		return
	}
	delete(a.grants, digest(token))
	session := randomAccessToken()
	a.sessions[digest(session)] = a.now().Add(adminLifetime)
	http.SetCookie(w, &http.Cookie{Name: adminCookie, Value: session, Path: "/", Secure: true, HttpOnly: true, SameSite: http.SameSiteLaxMode, MaxAge: int(adminLifetime.Seconds())})
	http.Redirect(w, r, "/dashboard/", http.StatusSeeOther)
}
func (a *AdminGate) authorized(r *http.Request) bool {
	c, err := r.Cookie(adminCookie)
	if err != nil || len(c.Value) != 64 {
		return false
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	expires, ok := a.sessions[digest(c.Value)]
	if ok && !a.now().Before(expires) {
		delete(a.sessions, digest(c.Value))
		return false
	}
	return ok
}

var publicAPI = regexp.MustCompile(`^/api/v1/(setting|ws/server|server-group|service|service/server|service/[0-9]+/history|server/[0-9]+/(service|metrics))$`)
var publicPage = regexp.MustCompile(`^/(server/[0-9]+/?|assets/[A-Za-z0-9_./-]+|[^/]+\.(css|js|svg|png|ico|webmanifest|txt|woff2?))$`)

func (a *AdminGate) Handler(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Never cache authorized pages, login responses, or denial responses at the CDN.
		w.Header().Set("Cache-Control", "private, no-store")
		w.Header().Set("Referrer-Policy", "no-referrer")
		w.Header().Add("Vary", "Cookie")
		if a.authorized(r) {
			next.ServeHTTP(w, r)
			return
		}
		p := r.URL.Path
		canonical := path.Clean(p) == strings.TrimSuffix(p, "/") || p == "/"
		if (r.Method != http.MethodGet && r.Method != http.MethodHead) || !canonical ||
			!(p == "/" || p == "/manifest.json" || publicAPI.MatchString(p) || publicPage.MatchString(p)) {
			http.NotFound(w, r)
			return
		}
		// A previously issued Nezha JWT/PAT cannot bypass the entry gate through a
		// public endpoint. Public views always use guest permissions without a grant.
		clone := r.Clone(r.Context())
		clone.Header = r.Header.Clone()
		clone.Header.Del("Authorization")
		clone.Header.Del("Cookie")
		q := clone.URL.Query()
		q.Del("token")
		clone.URL.RawQuery = q.Encode()
		next.ServeHTTP(w, clone)
	})
}
