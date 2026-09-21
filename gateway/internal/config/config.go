package config

import (
	"bufio"
	"errors"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Port                      int
	BackendAPIURL             *url.URL
	PathPrefix                string
	APIPrefix                 string
	SubscriptionPrefix        string
	BackendSubscriptionPrefix string
	CORSOrigin                string
	AllowedOrigins            []string
	RequestTimeout            time.Duration
	EnableLogging             bool
	DebugMode                 bool
	AllowedPaymentNotifyPaths []string
	AESKey                    []byte
}

func Load(envFile string) (Config, error) {
	if envFile != "" {
		if err := loadEnvFile(envFile); err != nil && !errors.Is(err, os.ErrNotExist) {
			return Config{}, err
		}
	}

	port, err := intEnv("PORT", 3939)
	if err != nil {
		return Config{}, err
	}
	backendRaw := strings.TrimSpace(os.Getenv("BACKEND_API_URL"))
	if backendRaw == "" {
		return Config{}, errors.New("BACKEND_API_URL is required")
	}
	backend, err := url.Parse(backendRaw)
	if err != nil || backend.Scheme == "" || backend.Host == "" {
		return Config{}, fmt.Errorf("invalid BACKEND_API_URL: %q", backendRaw)
	}
	if backend.Scheme != "http" && backend.Scheme != "https" {
		return Config{}, errors.New("BACKEND_API_URL must use http or https")
	}

	key := []byte(os.Getenv("AES_KEY"))
	switch len(key) {
	case 16, 24, 32:
	default:
		return Config{}, fmt.Errorf("AES_KEY must be 16, 24, or 32 bytes, got %d", len(key))
	}

	timeoutMS, err := intEnv("REQUEST_TIMEOUT", 30000)
	if err != nil {
		return Config{}, err
	}

	subscriptionRaw := envAllowEmpty("SUBSCRIPTION_PREFIX", "/s")
	backendSubscriptionRaw := envAllowEmpty("BACKEND_SUBSCRIPTION_PREFIX", subscriptionRaw)
	paymentNotifyRaw := envAllowEmpty("ALLOWED_PAYMENT_NOTIFY_PATHS", "/api/v1/guest/payment/notify")

	cfg := Config{
		Port:                      port,
		BackendAPIURL:             backend,
		PathPrefix:                cleanPrefix(envOr("PATH_PREFIX", "/dui/gw")),
		APIPrefix:                 cleanPrefix(envOr("API_PREFIX", "/api/v1")),
		SubscriptionPrefix:        optionalPrefix(subscriptionRaw),
		BackendSubscriptionPrefix: optionalPrefix(backendSubscriptionRaw),
		CORSOrigin:                strings.TrimSpace(envOr("CORS_ORIGIN", "*")),
		AllowedOrigins:            splitCSV(envOr("ALLOWED_ORIGINS", "*")),
		RequestTimeout:            time.Duration(timeoutMS) * time.Millisecond,
		EnableLogging:             boolEnv("ENABLE_LOGGING", false),
		DebugMode:                 boolEnv("DEBUG_MODE", false),
		AllowedPaymentNotifyPaths: splitCSV(paymentNotifyRaw),
		AESKey:                    key,
	}
	if cfg.Port < 1 || cfg.Port > 65535 {
		return Config{}, fmt.Errorf("PORT must be between 1 and 65535")
	}
	if cfg.PathPrefix == "/" {
		return Config{}, errors.New("PATH_PREFIX cannot be /")
	}
	if cfg.SubscriptionPrefix == "/" {
		return Config{}, errors.New("SUBSCRIPTION_PREFIX cannot be /")
	}
	if cfg.BackendSubscriptionPrefix == "/" {
		return Config{}, errors.New("BACKEND_SUBSCRIPTION_PREFIX cannot be /")
	}
	return cfg, nil
}

func loadEnvFile(path string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()

	s := bufio.NewScanner(f)
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		v = strings.TrimSpace(v)
		if len(v) >= 2 && ((v[0] == '"' && v[len(v)-1] == '"') || (v[0] == '\'' && v[len(v)-1] == '\'')) {
			v = v[1 : len(v)-1]
		}
		if _, exists := os.LookupEnv(k); !exists {
			_ = os.Setenv(k, v)
		}
	}
	return s.Err()
}

func envOr(k, def string) string {
	if v, ok := os.LookupEnv(k); ok && strings.TrimSpace(v) != "" {
		return strings.TrimSpace(v)
	}
	return def
}

func envAllowEmpty(k, def string) string {
	if v, ok := os.LookupEnv(k); ok {
		return strings.TrimSpace(v)
	}
	return def
}

func intEnv(k string, def int) (int, error) {
	raw := envOr(k, strconv.Itoa(def))
	v, err := strconv.Atoi(raw)
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer", k)
	}
	return v, nil
}

func boolEnv(k string, def bool) bool {
	raw, ok := os.LookupEnv(k)
	if !ok {
		return def
	}
	v, err := strconv.ParseBool(strings.TrimSpace(raw))
	if err != nil {
		return def
	}
	return v
}

func splitCSV(v string) []string {
	var out []string
	for _, item := range strings.Split(v, ",") {
		item = strings.TrimSpace(item)
		if item != "" {
			out = append(out, item)
		}
	}
	return out
}

func optionalPrefix(v string) string {
	v = strings.TrimSpace(v)
	if v == "" || v == "-" || strings.EqualFold(v, "off") || strings.EqualFold(v, "none") {
		return ""
	}
	return cleanPrefix(v)
}

func cleanPrefix(v string) string {
	v = strings.TrimSpace(v)
	if v == "" {
		return "/"
	}
	if !strings.HasPrefix(v, "/") {
		v = "/" + v
	}
	if len(v) > 1 {
		v = strings.TrimRight(v, "/")
	}
	return v
}
