package bridge

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/url"
	"strings"
)

const Prefix = "/bridge/v1"
const MaxBody = 16 << 20

type Frame struct {
	ID      string            `json:"id"`
	Kind    string            `json:"kind"`
	Device  string            `json:"device,omitempty"`
	Method  string            `json:"method,omitempty"`
	Path    string            `json:"path,omitempty"`
	Query   url.Values        `json:"query,omitempty"`
	Headers map[string]string `json:"headers,omitempty"`
	Body    []byte            `json:"body,omitempty"`
	Status  int               `json:"status,omitempty"`
}

func ID() string {
	b := make([]byte, 24)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func AllowedPath(p string) bool {
	switch p {
	case "/api/v2/server/handshake", "/api/v2/server/config", "/api/v2/server/user", "/api/v2/server/report", "/api/v2/server/machine/nodes", "/api/v2/server/machine/status":
		return true
	}
	return false
}
func PublicURL(raw string, allowLocal bool) (*url.URL, error) {
	u, e := url.Parse(raw)
	if e != nil {
		return nil, e
	}
	if u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") {
		return nil, ErrURL
	}
	if u.Scheme != "https" && !(allowLocal && u.Scheme == "http" && (u.Hostname() == "127.0.0.1" || u.Hostname() == "localhost")) {
		return nil, ErrURL
	}
	u.Path = ""
	return u, nil
}
func bearer(r *http.Request) string {
	return strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
}
func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
