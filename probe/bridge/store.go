package bridge

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"
)

var ErrURL = errors.New("probe endpoint must be an HTTPS origin")
var ErrUnauthorized = errors.New("invalid or expired probe credential")

type Device struct {
	UUID          string `json:"uuid"`
	Name          string `json:"name"`
	Enabled       bool   `json:"enabled"`
	SecretHash    string `json:"secret_hash,omitempty"`
	EnrollHash    string `json:"enroll_hash,omitempty"`
	EnrollExpires int64  `json:"enroll_expires,omitempty"`
	ServerID      uint64 `json:"server_id"`
}
type Registry struct {
	mu      sync.RWMutex
	path    string
	devices map[string]Device
}

func NewRegistry(path string) (*Registry, error) {
	s := &Registry{path: path, devices: map[string]Device{}}
	b, e := os.ReadFile(path)
	if e != nil && !os.IsNotExist(e) {
		return nil, e
	}
	if e == nil {
		if e = json.Unmarshal(b, &s.devices); e != nil {
			return nil, e
		}
	}
	return s, nil
}
func digest(s string) string { x := sha256.Sum256([]byte(s)); return hex.EncodeToString(x[:]) }
func equal(a, b string) bool {
	return len(a) > 0 && subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}
func (s *Registry) saveLocked() error {
	b, e := json.Marshal(s.devices)
	if e != nil {
		return e
	}
	if e = os.MkdirAll(filepath.Dir(s.path), 0700); e != nil {
		return e
	}
	f, e := os.CreateTemp(filepath.Dir(s.path), ".registry-*")
	if e != nil {
		return e
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if e = f.Chmod(0600); e == nil {
		_, e = f.Write(b)
	}
	if e == nil {
		e = f.Sync()
	}
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if e == nil {
		e = os.Rename(tmp, s.path)
	}
	if e == nil {
		d, er := os.Open(filepath.Dir(s.path))
		if er == nil {
			e = d.Sync()
			d.Close()
		}
	}
	return e
}
func (s *Registry) Put(d Device) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	old, ok := s.devices[d.UUID]
	s.devices[d.UUID] = d
	if e := s.saveLocked(); e != nil {
		if ok {
			s.devices[d.UUID] = old
		} else {
			delete(s.devices, d.UUID)
		}
		return e
	}
	return nil
}
func (s *Registry) Get(id string) (Device, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	d, ok := s.devices[id]
	return d, ok
}
func (s *Registry) Authorize(id, secret string) (Device, bool) {
	d, ok := s.Get(id)
	return d, ok && d.Enabled && equal(d.SecretHash, digest(secret))
}
func (s *Registry) Enroll(id, code, secret string) (Device, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	d, ok := s.devices[id]
	if !ok || !d.Enabled || len(secret) < 32 {
		return Device{}, ErrUnauthorized
	}
	// Repeating a successful enrollment with the same device key is safe after a lost response.
	if d.EnrollHash == "" && equal(d.SecretHash, digest(secret)) {
		return d, nil
	}
	if d.EnrollExpires < time.Now().Unix() || !equal(d.EnrollHash, digest(code)) {
		return Device{}, ErrUnauthorized
	}
	old := d
	d.SecretHash = digest(secret)
	d.EnrollHash = ""
	d.EnrollExpires = 0
	s.devices[id] = d
	if e := s.saveLocked(); e != nil {
		s.devices[id] = old
		return Device{}, e
	}
	return d, nil
}
