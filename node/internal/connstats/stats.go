// Package connstats collects connection metadata, never payloads or credentials.
// History uses one-minute buckets with bounded cardinality and explicit truncation.
package connstats

import (
	"net"
	"sort"
	"strings"
	"sync"
	"time"
)

const maxEntries = 100000
const maxRows = 300

type Row struct {
	Value   string  `json:"value"`
	Active  int     `json:"active"`
	Count   int64   `json:"count"`
	Seconds float64 `json:"seconds"`
}
type Snapshot struct {
	Version    int   `json:"version"`
	At         int64 `json:"at"`
	Since      int64 `json:"since"`
	SourceIPs  int   `json:"source_ips"`
	TCP        int   `json:"tcp"`
	UDP        int   `json:"udp"`
	Sources    []Row `json:"sources"`
	TCPRows    []Row `json:"tcp_rows"`
	UDPRows    []Row `json:"udp_rows"`
	Truncated  bool  `json:"truncated"`
	Resolution int   `json:"resolution_seconds"`
}
type key struct{ kind, value string }
type counter struct {
	count   int64
	seconds float64
}
type Session struct {
	source, target, network string
	last                    time.Time
}
type Store struct {
	path      string
	persistMu sync.Mutex
	lastPrune int64
	mu        sync.Mutex
	active    map[*Session]struct{}
	buckets   map[int64]map[key]*counter
	entries   int
	since     time.Time
	lostUntil time.Time
	now       func() time.Time
}

func New() *Store { return &Store{now: time.Now} }
func (s *Store) init(now time.Time) {
	if s.active == nil {
		s.active = make(map[*Session]struct{})
		s.buckets = make(map[int64]map[key]*counter)
		s.since = now
	}
}
func normalizeIP(ip string) string {
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return "未知"
	}
	return parsed.String()
}
func (s *Store) Begin(source, target, network string) func() {
	if s == nil {
		return func() {}
	}
	now := s.now()
	network = strings.ToLower(network)
	if network != "tcp" && network != "udp" {
		return func() {}
	}
	if len(target) > 512 {
		target = target[:512]
	}
	if target == "" {
		target = "未知"
	}
	c := &Session{source: normalizeIP(source), target: target, network: network, last: now}
	s.mu.Lock()
	s.init(now)
	s.prune(now)
	s.active[c] = struct{}{}
	s.add(now.Unix()/60, key{"source", c.source}, 1, 0, now)
	s.add(now.Unix()/60, key{network, target}, 1, 0, now)
	s.mu.Unlock()
	var once sync.Once
	return func() {
		once.Do(func() {
			end := s.now()
			s.mu.Lock()
			defer s.mu.Unlock()
			s.accrue(c, end)
			delete(s.active, c)
		})
	}
}
func (s *Store) add(minute int64, k key, count int64, seconds float64, now time.Time) {
	bucket := s.buckets[minute]
	if bucket == nil {
		bucket = make(map[key]*counter)
		s.buckets[minute] = bucket
	}
	v := bucket[k]
	if v == nil {
		if s.entries >= maxEntries {
			s.lostUntil = now.Add(24 * time.Hour)
			return
		}
		v = &counter{}
		bucket[k] = v
		s.entries++
	}
	v.count += count
	v.seconds += seconds
}
func (s *Store) prune(now time.Time) {
	minuteNow := now.Unix() / 60
	if s.lastPrune == minuteNow {
		return
	}
	s.lastPrune = minuteNow
	cutoff := now.Add(-24*time.Hour).Unix() / 60
	for minute, b := range s.buckets {
		if minute < cutoff {
			s.entries -= len(b)
			delete(s.buckets, minute)
		}
	}
}
func (s *Store) accrue(c *Session, now time.Time) {
	from := c.last
	if min := now.Add(-24 * time.Hour); from.Before(min) {
		from = min
	}
	for from.Before(now) {
		minute := from.Unix() / 60
		end := time.Unix((minute+1)*60, 0)
		if end.After(now) {
			end = now
		}
		seconds := end.Sub(from).Seconds()
		s.add(minute, key{"source", c.source}, 0, seconds, now)
		s.add(minute, key{c.network, c.target}, 0, seconds, now)
		from = end
	}
	c.last = now
}
func (s *Store) Snapshot() Snapshot {
	now := s.now()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.init(now)
	s.prune(now)
	for c := range s.active {
		s.accrue(c, now)
	}
	result := Snapshot{Version: 1, At: now.Unix(), Since: s.since.Unix(), Resolution: 60, Truncated: now.Before(s.lostUntil)}
	if cutoff := now.Add(-24 * time.Hour).Unix(); result.Since < cutoff {
		result.Since = cutoff
	}
	rows := make(map[key]*Row)
	get := func(k key) *Row {
		r := rows[k]
		if r == nil {
			r = &Row{Value: k.value}
			rows[k] = r
		}
		return r
	}
	// The oldest minute is weighted to avoid including time outside the window.
	cutoff := now.Add(-24 * time.Hour)
	for minute, b := range s.buckets {
		weight := 1.0
		if minute == cutoff.Unix()/60 {
			weight = float64((minute+1)*60-cutoff.Unix()) / 60
		}
		for k, v := range b {
			r := get(k)
			r.Count += v.count
			r.Seconds += v.seconds * weight
		}
	}
	ips := make(map[string]struct{})
	for c := range s.active {
		if c.source != "未知" {
			ips[c.source] = struct{}{}
		}
		get(key{"source", c.source}).Active++
		get(key{c.network, c.target}).Active++
		if c.network == "tcp" {
			result.TCP++
		} else {
			result.UDP++
		}
	}
	result.SourceIPs = len(ips)
	result.Sources = []Row{}
	result.TCPRows = []Row{}
	result.UDPRows = []Row{}
	for k, r := range rows {
		switch k.kind {
		case "source":
			result.Sources = append(result.Sources, *r)
		case "tcp":
			result.TCPRows = append(result.TCPRows, *r)
		case "udp":
			result.UDPRows = append(result.UDPRows, *r)
		}
	}
	trim := func(v []Row) []Row {
		sort.Slice(v, func(i, j int) bool {
			if v[i].Active != v[j].Active {
				return v[i].Active > v[j].Active
			}
			if v[i].Count != v[j].Count {
				return v[i].Count > v[j].Count
			}
			return v[i].Value < v[j].Value
		})
		if len(v) > maxRows {
			result.Truncated = true
			v = v[:maxRows]
		}
		return v
	}
	result.Sources = trim(result.Sources)
	result.TCPRows = trim(result.TCPRows)
	result.UDPRows = trim(result.UDPRows)
	return result
}
