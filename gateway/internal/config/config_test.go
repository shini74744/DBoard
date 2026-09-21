package config

import "testing"

func TestExplicitlyDisablePassthrough(t *testing.T) {
	t.Setenv("BACKEND_API_URL", "http://127.0.0.1:7001")
	t.Setenv("AES_KEY", "0123456789abcdef")
	t.Setenv("SUBSCRIPTION_PREFIX", "off")
	t.Setenv("BACKEND_SUBSCRIPTION_PREFIX", "off")
	t.Setenv("ALLOWED_PAYMENT_NOTIFY_PATHS", "")

	cfg, err := Load("")
	if err != nil {
		t.Fatal(err)
	}
	if cfg.SubscriptionPrefix != "" {
		t.Fatalf("subscription prefix=%q", cfg.SubscriptionPrefix)
	}
	if cfg.BackendSubscriptionPrefix != "" {
		t.Fatalf("backend subscription prefix=%q", cfg.BackendSubscriptionPrefix)
	}
	if len(cfg.AllowedPaymentNotifyPaths) != 0 {
		t.Fatalf("payment paths=%v", cfg.AllowedPaymentNotifyPaths)
	}
}
