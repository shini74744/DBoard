package gateway

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"encoding/base64"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/shini74744/DBoard/gateway/internal/config"
)

func testConfig(t *testing.T, backend string) config.Config {
	t.Helper()
	u, err := url.Parse(backend)
	if err != nil {
		t.Fatal(err)
	}
	return config.Config{
		Port:                      3939,
		BackendAPIURL:             u,
		PathPrefix:                "/dui/gw",
		APIPrefix:                 "/api/v1",
		SubscriptionPrefix:        "/s",
		BackendSubscriptionPrefix: "/s",
		CORSOrigin:                "*",
		AllowedOrigins:            []string{"*"},
		RequestTimeout:            5 * time.Second,
		AllowedPaymentNotifyPaths: []string{"/api/v1/guest/payment/notify"},
		AESKey:                    []byte("0123456789abcdef"),
	}
}

func encryptLikeJC(t *testing.T, plain, iv string, key []byte) string {
	t.Helper()
	block, err := aes.NewCipher(key)
	if err != nil {
		t.Fatal(err)
	}
	pad := aes.BlockSize - len(plain)%aes.BlockSize
	padded := append([]byte(plain), bytes.Repeat([]byte{byte(pad)}, pad)...)
	out := make([]byte, len(padded))
	cipher.NewCBCEncrypter(block, []byte(iv)).CryptBlocks(out, padded)
	inner := base64.StdEncoding.EncodeToString(out)
	return base64.StdEncoding.EncodeToString([]byte(inner))
}

func TestEncryptedAliasProxy(t *testing.T) {
	var gotPath, gotQuery, gotAuth, gotBody string
	backend := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		gotQuery = r.URL.RawQuery
		gotAuth = r.Header.Get("Authorization")
		body, _ := io.ReadAll(r.Body)
		gotBody = string(body)
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer backend.Close()

	cfg := testConfig(t, backend.URL)
	g := New(cfg, "test")
	iv := "0123456789abcdef"
	token := encryptLikeJC(t, "/g/conf?hello=world", iv, cfg.AESKey)

	req := httptest.NewRequest(http.MethodPost, "/dui/gw/"+token, strings.NewReader("a=1"))
	req.Header.Set("X-IV", iv)
	req.Header.Set("Authorization", "Bearer test")
	rr := httptest.NewRecorder()
	g.ServeHTTP(rr, req)

	if rr.Code != http.StatusCreated {
		t.Fatalf("status=%d body=%s", rr.Code, rr.Body.String())
	}
	if gotPath != "/api/v1/guest/comm/config" {
		t.Fatalf("path=%q", gotPath)
	}
	if gotQuery != "hello=world" {
		t.Fatalf("query=%q", gotQuery)
	}
	if gotAuth != "Bearer test" {
		t.Fatalf("auth=%q", gotAuth)
	}
	if gotBody != "a=1" {
		t.Fatalf("body=%q", gotBody)
	}
}

func TestEncryptedUnknownPathProxy(t *testing.T) {
	var got string
	backend := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.RequestURI()
		w.WriteHeader(http.StatusNoContent)
	}))
	defer backend.Close()

	cfg := testConfig(t, backend.URL)
	g := New(cfg, "test")
	iv := "fedcba9876543210"
	token := encryptLikeJC(t, "/custom/new/endpoint?a=1", iv, cfg.AESKey)

	req := httptest.NewRequest(http.MethodGet, "/dui/gw/"+token, nil)
	req.Header.Set("X-IV", iv)
	rr := httptest.NewRecorder()
	g.ServeHTTP(rr, req)

	if rr.Code != http.StatusNoContent {
		t.Fatalf("status=%d", rr.Code)
	}
	if got != "/api/v1/custom/new/endpoint?a=1" {
		t.Fatalf("upstream=%q", got)
	}
}

func TestSubscriptionAndPaymentPassthrough(t *testing.T) {
	var paths []string
	backend := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.RequestURI())
		w.WriteHeader(http.StatusOK)
	}))
	defer backend.Close()

	cfg := testConfig(t, backend.URL)
	g := New(cfg, "test")

	for _, raw := range []string{
		"/s/token123?flag=1",
		"/api/v1/guest/payment/notify/Stripe/uuid123",
	} {
		req := httptest.NewRequest(http.MethodGet, raw, nil)
		rr := httptest.NewRecorder()
		g.ServeHTTP(rr, req)
		if rr.Code != http.StatusOK {
			t.Fatalf("%s status=%d", raw, rr.Code)
		}
	}

	if len(paths) != 2 || paths[0] != "/s/token123?flag=1" || paths[1] != "/api/v1/guest/payment/notify/Stripe/uuid123" {
		t.Fatalf("paths=%v", paths)
	}
}

func TestRejectUnsafeDecryptedTarget(t *testing.T) {
	backend := httptest.NewServer(http.NotFoundHandler())
	defer backend.Close()

	cfg := testConfig(t, backend.URL)
	g := New(cfg, "test")
	iv := "0123456789abcdef"
	token := encryptLikeJC(t, "https://evil.example/path", iv, cfg.AESKey)

	req := httptest.NewRequest(http.MethodGet, "/dui/gw/"+token, nil)
	req.Header.Set("X-IV", iv)
	rr := httptest.NewRecorder()
	g.ServeHTTP(rr, req)
	if rr.Code != http.StatusBadRequest {
		t.Fatalf("status=%d", rr.Code)
	}
}

func TestOriginAllowlist(t *testing.T) {
	backend := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer backend.Close()

	cfg := testConfig(t, backend.URL)
	cfg.AllowedOrigins = []string{"https://front.example.com"}
	cfg.CORSOrigin = "https://front.example.com"
	g := New(cfg, "test")

	req := httptest.NewRequest(http.MethodGet, "/s/token", nil)
	req.Header.Set("Origin", "https://evil.example")
	rr := httptest.NewRecorder()
	g.ServeHTTP(rr, req)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("status=%d", rr.Code)
	}

	req2 := httptest.NewRequest(http.MethodGet, "/s/token", nil)
	req2.Header.Set("Origin", "https://front.example.com")
	rr2 := httptest.NewRecorder()
	g.ServeHTTP(rr2, req2)
	if rr2.Code != http.StatusOK {
		t.Fatalf("status=%d", rr2.Code)
	}
	if rr2.Header().Get("Access-Control-Allow-Origin") != "https://front.example.com" {
		t.Fatalf("cors=%q", rr2.Header().Get("Access-Control-Allow-Origin"))
	}
}
