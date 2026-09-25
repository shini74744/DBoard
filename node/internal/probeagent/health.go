package probeagent

import (
	"encoding/json"
	"os"
	"path/filepath"
	"time"
)

type Health struct {
	Version    string `json:"version"`
	UpdatedAt  int64  `json:"updated_at"`
	Monitoring bool   `json:"monitoring"`
}

func WriteHealth(c Config, version string, monitoring bool) error {
	b, _ := json.Marshal(Health{version, time.Now().Unix(), monitoring})
	return atomicWrite(filepath.Join(c.DataDir, "health.json"), b, 0600)
}
func HealthySince(c Config, version string, since int64) bool {
	b, e := os.ReadFile(filepath.Join(c.DataDir, "health.json"))
	if e != nil {
		return false
	}
	var h Health
	if json.Unmarshal(b, &h) != nil {
		return false
	}
	return h.Version == version && h.Monitoring && h.UpdatedAt >= since && h.UpdatedAt >= time.Now().Unix()-60
}
