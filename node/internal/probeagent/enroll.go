package probeagent

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/shini74744/DBoard/probe/bridge"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// Reuse the existing scoped identity on reinstall. Persist the candidate before
// enrollment so a lost HTTP response can be retried with exactly the same key.
func Enroll(ctx context.Context, path, endpoint, uuid, code string) error {
	if !bridge.UUIDPattern.MatchString(uuid) || len(code) != 48 {
		return fmt.Errorf("invalid enrollment parameters")
	}
	if _, e := bridge.PublicURL(endpoint, false); e != nil {
		return e
	}
	c := Config{Endpoint: endpoint, UUID: uuid, Secret: bridge.ID(), Kernel: "singbox"}
	existing, e := Load(path)
	if e == nil {
		if existing.UUID != uuid {
			return fmt.Errorf("existing Agent belongs to another server")
		}
		c = existing
		c.Endpoint = endpoint
	} else if !os.IsNotExist(e) {
		return e
	} else if pending, e := Load(path + ".enrolling"); e == nil && pending.UUID == uuid {
		c = pending
		c.Endpoint = endpoint
	}
	if e = c.Validate(); e != nil {
		return e
	}
	if e = os.MkdirAll(filepath.Dir(path), 0700); e != nil {
		return e
	}
	if e = c.Save(path + ".enrolling"); e != nil {
		return e
	}
	b, _ := json.Marshal(map[string]string{"uuid": uuid, "code": code, "secret": c.Secret})
	req, e := http.NewRequestWithContext(ctx, "POST", endpoint+bridge.Prefix+"/enroll", bytes.NewReader(b))
	if e != nil {
		return e
	}
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	resp, e := client.Do(req)
	if e != nil {
		return fmt.Errorf("probe enrollment connection failed")
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("probe enrollment rejected: %d", resp.StatusCode)
	}
	if e = c.Save(path); e != nil {
		return e
	}
	_ = os.Remove(path + ".enrolling")
	return nil
}
