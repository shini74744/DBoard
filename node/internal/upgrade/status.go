package upgrade

import (
	"encoding/json"
	"os"
	"path/filepath"
	"time"
)

var StatusPath = "/etc/DBoard-node/upgrade-status.json"

type Status struct {
	RequestID string `json:"request_id"`
	Version   string `json:"target_version"`
	State     string `json:"state"`
	Message   string `json:"message"`
	UpdatedAt int64  `json:"updated_at"`
}

func ValidRequest(id, version string) bool {
	return requestPattern.MatchString(id) && versionPattern.MatchString(version)
}
func WriteStatus(path string, s Status) error {
	s.UpdatedAt = time.Now().Unix()
	data, err := json.Marshal(s)
	if err != nil {
		return err
	}
	f, err := os.CreateTemp(filepath.Dir(path), ".upgrade-status-*")
	if err != nil {
		return err
	}
	name := f.Name()
	defer os.Remove(name)
	if _, err = f.Write(data); err != nil {
		f.Close()
		return err
	}
	if err = f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err = f.Close(); err != nil {
		return err
	}
	return os.Rename(name, path)
}
func ReadStatus(path string) (*Status, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var s Status
	if err = json.Unmarshal(data, &s); err != nil {
		return nil, err
	}
	if !ValidRequest(s.RequestID, s.Version) || s.UpdatedAt < time.Now().Add(-24*time.Hour).Unix() {
		return nil, nil
	}
	return &s, nil
}
