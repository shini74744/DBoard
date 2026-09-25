package bridge

import (
	"bytes"
	"errors"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"
)

func TestDeviceDeletionRevokesAndCanRetry(t *testing.T) {
	reg, err := NewRegistry(filepath.Join(t.TempDir(), "devices.json"))
	if err != nil {
		t.Fatal(err)
	}
	secret, code, key := ID(), ID(), ID()
	if err := reg.Put(Device{UUID: deviceID, Enabled: true, ServerID: 7, SecretHash: digest(secret), EnrollHash: digest(code), EnrollExpires: time.Now().Add(time.Hour).Unix()}); err != nil {
		t.Fatal(err)
	}
	fail, calls := true, 0
	g := &Gateway{Registry: reg, ControlKey: key, Deprovision: func(d Device) error {
		calls++
		if d.UUID != deviceID || d.Enabled || !d.Deleted {
			t.Fatal("invalid deletion identity")
		}
		if _, ok := reg.Authorize(deviceID, secret); ok {
			t.Fatal("credential not revoked before deleting inventory")
		}
		if fail {
			return errors.New("database unavailable")
		}
		return nil
	}}
	request := func(method, path, token, body string) int {
		r := httptest.NewRequest(method, Prefix+path, bytes.NewBufferString(body))
		r.Header.Set("Authorization", "Bearer "+token)
		w := httptest.NewRecorder()
		g.Handler(http.NotFoundHandler()).ServeHTTP(w, r)
		return w.Code
	}
	body := "{" + "\"uuid\":\"" + deviceID + "\"}"
	if got := request("POST", "/control/device/delete", "wrong", body); got != 401 {
		t.Fatal(got)
	}
	if got := request("GET", "/control/device/delete", key, body); got != 405 {
		t.Fatal(got)
	}
	if got := request("POST", "/control/device/delete", key, "{}"); got != 422 {
		t.Fatal(got)
	}
	if calls != 0 {
		t.Fatal("unauthorized deletion")
	}
	if got := request("POST", "/control/device/delete", key, body); got != 503 {
		t.Fatal(got)
	}
	d, _ := reg.Get(deviceID)
	if d.Enabled || !d.Deleted || d.SecretHash != "" || d.EnrollHash != "" || d.EnrollExpires != 0 {
		t.Fatal("credentials retained")
	}
	if _, err := reg.Enroll(deviceID, code, ID()); err == nil {
		t.Fatal("deleted device enrolled")
	}
	reopened, err := NewRegistry(reg.path)
	if err != nil {
		t.Fatal(err)
	}
	if d, ok := reopened.Get(deviceID); !ok || !d.Deleted {
		t.Fatal("tombstone not durable")
	}
	fail = false
	for i := 0; i < 2; i++ {
		if got := request("POST", "/control/device/delete", key, body); got != 200 {
			t.Fatal(got)
		}
	}
	if got := request("POST", "/control/device", key, body); got != 410 {
		t.Fatal("deleted identity recreated", got)
	}
}
