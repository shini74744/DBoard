package connstats

import (
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"time"
)

type diskEntry struct {
	UserID      int
	Minute      int64
	Kind, Value string
	Count       int64
	Seconds     float64
}
type diskState struct {
	UserSince int64
	Since     int64
	LostUntil int64
	Entries   []diskEntry
}

// Restore is called before listeners start. Active connections are intentionally
// not restored; only previously observed history survives a service restart.
func (s *Store) Restore(path string) error {
	s.path = path
	f, err := os.Open(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	defer f.Close()
	var data diskState
	if err = json.NewDecoder(io.LimitReader(f, 40<<20)).Decode(&data); err != nil {
		return err
	}
	now := s.now()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.init(now)
	if data.Since > 0 && data.Since <= now.Unix() {
		s.since = time.Unix(data.Since, 0)
	}
	if data.UserSince > 0 && data.UserSince <= now.Unix() {
		s.userSince = time.Unix(data.UserSince, 0)
	}
	s.lostUntil = time.Unix(data.LostUntil, 0)
	for _, e := range data.Entries {
		if s.entries >= maxEntries {
			s.lostUntil = now.Add(24 * time.Hour)
			break
		}
		if e.Minute < now.Add(-24*time.Hour).Unix()/60 || e.Minute > now.Unix()/60 {
			continue
		}
		if e.Kind != "source" && e.Kind != "tcp" && e.Kind != "udp" {
			continue
		}
		if len(e.Value) > 512 || e.Count < 0 || e.Seconds < 0 {
			continue
		}
		s.add(e.Minute, key{e.Kind, e.Value, e.UserID}, e.Count, e.Seconds, now)
	}
	return nil
}
func (s *Store) Save() error {
	if s.path == "" {
		return nil
	}
	s.persistMu.Lock()
	defer s.persistMu.Unlock()
	if !s.lastSave.IsZero() && s.now().Sub(s.lastSave) < time.Minute {
		return nil
	}
	s.mu.Lock()
	data := diskState{UserSince: s.userSince.Unix(), Since: s.since.Unix(), LostUntil: s.lostUntil.Unix(), Entries: make([]diskEntry, 0, s.entries)}
	for minute, b := range s.buckets {
		for k, v := range b {
			data.Entries = append(data.Entries, diskEntry{k.userID, minute, k.kind, k.value, v.count, v.seconds})
		}
	}
	s.mu.Unlock()
	if err := os.MkdirAll(filepath.Dir(s.path), 0700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(s.path), ".connections-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err = json.NewEncoder(tmp).Encode(data); err != nil {
		tmp.Close()
		return err
	}
	if err = tmp.Close(); err != nil {
		return err
	}
	if err = os.Rename(tmp.Name(), s.path); err == nil {
		s.lastSave = s.now()
	}
	return err
}
