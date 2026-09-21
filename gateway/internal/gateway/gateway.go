package gateway

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/shini74744/DBoard/gateway/internal/config"
)

type Gateway struct {
	cfg     config.Config
	client  *http.Client
	version string
}

func New(cfg config.Config, version string) *Gateway {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.MaxIdleConns = 256
	transport.MaxIdleConnsPerHost = 128
	transport.IdleConnTimeout = 90 * time.Second
	return &Gateway{
		cfg: cfg,
		client: &http.Client{
			Transport: transport,
		},
		version: version,
	}
}

func (g *Gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/healthz" {
		g.applyCORS(w, r)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok":      true,
			"service": "DUI-Gateway",
			"version": g.version,
		})
		return
	}

	if !g.originAllowed(r.Header.Get("Origin")) {
		http.Error(w, "origin not allowed", http.StatusForbidden)
		return
	}
	g.applyCORS(w, r)

	if r.Method == http.MethodOptions {
		w.WriteHeader(http.StatusNoContent)
		return
	}

	var (
		upstreamPath  string
		upstreamQuery string
		routeKind     string
	)

	switch {
	case r.URL.Path == g.cfg.PathPrefix || strings.HasPrefix(r.URL.Path, g.cfg.PathPrefix+"/"):
		target, err := g.decryptTarget(r)
		if err != nil {
			if g.cfg.DebugMode {
				log.Printf("DUI-Gateway decrypt rejected: %v", err)
			}
			http.Error(w, "invalid gateway request", http.StatusBadRequest)
			return
		}
		upstreamPath = joinPath(g.cfg.APIPrefix, target.Path)
		upstreamQuery = target.RawQuery
		routeKind = "encrypted-api"

	case hasPathPrefix(r.URL.Path, g.cfg.SubscriptionPrefix):
		suffix := strings.TrimPrefix(r.URL.Path, g.cfg.SubscriptionPrefix)
		upstreamPath = joinPath(g.cfg.BackendSubscriptionPrefix, suffix)
		upstreamQuery = r.URL.RawQuery
		routeKind = "subscription"

	case g.isPaymentNotifyPath(r.URL.Path):
		upstreamPath = r.URL.Path
		upstreamQuery = r.URL.RawQuery
		routeKind = "payment-notify"

	default:
		writeNotFound(w)
		return
	}

	if err := g.proxy(w, r, upstreamPath, upstreamQuery, routeKind); err != nil {
		if g.cfg.EnableLogging || g.cfg.DebugMode {
			log.Printf("DUI-Gateway proxy error kind=%s method=%s path=%s err=%v", routeKind, r.Method, upstreamPath, err)
		}
		http.Error(w, "bad gateway", http.StatusBadGateway)
	}
}

func (g *Gateway) decryptTarget(r *http.Request) (*url.URL, error) {
	token := strings.TrimPrefix(r.URL.Path, g.cfg.PathPrefix)
	token = strings.TrimPrefix(token, "/")
	if token == "" || len(token) > 16*1024 {
		return nil, errors.New("missing or oversized encrypted path")
	}

	plain, err := decryptPathToken(token, r.Header.Get("X-IV"), g.cfg.AESKey)
	if err != nil {
		return nil, err
	}
	u, err := url.ParseRequestURI(plain)
	if err != nil {
		return nil, errors.New("decrypted target is not a valid request URI")
	}
	if u.IsAbs() || u.Host != "" || !strings.HasPrefix(u.Path, "/") || strings.HasPrefix(u.Path, "//") || strings.Contains(u.Path, "\\") {
		return nil, errors.New("decrypted target must be a safe relative path")
	}
	u.Path = restoreAlias(u.Path)
	return u, nil
}

func (g *Gateway) proxy(w http.ResponseWriter, r *http.Request, upstreamPath, upstreamQuery, routeKind string) error {
	target := *g.cfg.BackendAPIURL
	target.Path = joinPath(target.Path, upstreamPath)
	target.RawPath = ""
	target.RawQuery = upstreamQuery

	ctx, cancel := context.WithTimeout(r.Context(), g.cfg.RequestTimeout)
	defer cancel()

	outReq := r.Clone(ctx)
	outReq.URL = &target
	outReq.RequestURI = ""
	outReq.Host = target.Host
	removeHopHeaders(outReq.Header)

	originalHost := r.Host
	if originalHost != "" {
		outReq.Header.Set("X-Forwarded-Host", originalHost)
	}
	outReq.Header.Set("X-Forwarded-Proto", requestProto(r))
	if ip, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		prior := outReq.Header.Get("X-Forwarded-For")
		if prior == "" {
			outReq.Header.Set("X-Forwarded-For", ip)
		} else {
			outReq.Header.Set("X-Forwarded-For", prior+", "+ip)
		}
		outReq.Header.Set("X-Real-IP", ip)
	}
	outReq.Header.Del("X-IV")

	start := time.Now()
	resp, err := g.client.Do(outReq)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	copyHeaders(w.Header(), resp.Header)
	removeHopHeaders(w.Header())
	g.applyCORS(w, r)
	w.WriteHeader(resp.StatusCode)
	if _, copyErr := io.Copy(w, resp.Body); copyErr != nil && (g.cfg.EnableLogging || g.cfg.DebugMode) {
		log.Printf("DUI-Gateway response copy error kind=%s path=%s err=%v", routeKind, upstreamPath, copyErr)
	}

	if g.cfg.EnableLogging {
		log.Printf("DUI-Gateway kind=%s method=%s upstream=%s status=%d duration=%s",
			routeKind, r.Method, upstreamPath, resp.StatusCode, time.Since(start).Round(time.Millisecond))
	}
	return nil
}

func (g *Gateway) originAllowed(origin string) bool {
	if origin == "" {
		return true
	}
	for _, allowed := range g.cfg.AllowedOrigins {
		if allowed == "*" || allowed == origin {
			return true
		}
		if strings.HasPrefix(allowed, "*.") {
			suffix := strings.TrimPrefix(allowed, "*")
			if strings.HasSuffix(originHost(origin), suffix) {
				return true
			}
		}
	}
	return false
}

func (g *Gateway) applyCORS(w http.ResponseWriter, r *http.Request) {
	origin := r.Header.Get("Origin")
	value := g.cfg.CORSOrigin
	if value == "" {
		value = origin
	}
	if value != "" {
		w.Header().Set("Access-Control-Allow-Origin", value)
	}
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type, X-IV, X-Requested-With, Accept, Origin")
	w.Header().Set("Access-Control-Expose-Headers", "Content-Length, Content-Range, Subscription-Userinfo")
	w.Header().Add("Vary", "Origin")
}

func (g *Gateway) isPaymentNotifyPath(p string) bool {
	for _, allowed := range g.cfg.AllowedPaymentNotifyPaths {
		if hasPathPrefix(p, allowed) {
			return true
		}
	}
	return false
}

func hasPathPrefix(p, prefix string) bool {
	if prefix == "" || prefix == "/" {
		return false
	}
	return p == prefix || strings.HasPrefix(p, prefix+"/")
}

func writeNotFound(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(http.StatusNotFound)
	_, _ = io.WriteString(w, `{"error":"路径未找到"}`)
}

func joinPath(a, b string) string {
	if a == "" || a == "/" {
		if b == "" {
			return "/"
		}
		if strings.HasPrefix(b, "/") {
			return b
		}
		return "/" + b
	}
	if b == "" || b == "/" {
		return strings.TrimRight(a, "/") + "/"
	}
	return strings.TrimRight(a, "/") + "/" + strings.TrimLeft(b, "/")
}

func requestProto(r *http.Request) string {
	if p := r.Header.Get("X-Forwarded-Proto"); p != "" {
		return strings.Split(p, ",")[0]
	}
	if r.TLS != nil {
		return "https"
	}
	return "http"
}

func originHost(origin string) string {
	u, err := url.Parse(origin)
	if err != nil {
		return ""
	}
	return u.Hostname()
}

var hopHeaders = []string{
	"Connection",
	"Proxy-Connection",
	"Keep-Alive",
	"Proxy-Authenticate",
	"Proxy-Authorization",
	"Te",
	"Trailer",
	"Transfer-Encoding",
	"Upgrade",
}

func removeHopHeaders(h http.Header) {
	for _, k := range hopHeaders {
		h.Del(k)
	}
}

func copyHeaders(dst, src http.Header) {
	for k := range dst {
		dst.Del(k)
	}
	for k, values := range src {
		for _, v := range values {
			dst.Add(k, v)
		}
	}
}

func ListenAddr(port int) string {
	return fmt.Sprintf("0.0.0.0:%d", port)
}
