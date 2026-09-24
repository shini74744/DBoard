package connstats

import (
	"sync"
	"testing"
	"time"
)

func TestWindowLifecycleAndDedup(t *testing.T) {
	now := time.Date(2026, 9, 24, 0, 0, 0, 0, time.UTC)
	s := New()
	s.now = func() time.Time { return now }
	tcp := s.Begin("1.1.1.1", "example.com:443", "tcp")
	udp := s.Begin("::ffff:1.1.1.1", "[2001:db8::1]:53", "udp")
	now = now.Add(30 * time.Second)
	v := s.Snapshot()
	if v.SourceIPs != 1 || v.TCP != 1 || v.UDP != 1 || v.Sources[0].Count != 2 || v.Sources[0].Seconds != 60 {
		t.Fatalf("%+v", v)
	}
	tcp()
	tcp()
	now = now.Add(30 * time.Second)
	udp()
	v = s.Snapshot()
	if v.TCP != 0 || v.UDP != 0 || v.SourceIPs != 0 || v.Sources[0].Count != 2 || v.Sources[0].Seconds != 90 {
		t.Fatalf("%+v", v)
	}
	now = now.Add(24*time.Hour + time.Minute)
	v = s.Snapshot()
	if len(v.Sources) != 0 {
		t.Fatalf("expired history remains: %+v", v)
	}
}
func TestLongConnectionOnlyCountsWindowDuration(t *testing.T) {
	now := time.Date(2026, 9, 24, 0, 0, 0, 0, time.UTC)
	s := New()
	s.now = func() time.Time { return now }
	done := s.Begin("1.1.1.1", "example.com:443", "tcp")
	now = now.Add(48 * time.Hour)
	v := s.Snapshot()
	if v.TCP != 1 || v.TCPRows[0].Count != 0 || v.TCPRows[0].Seconds != 86400 {
		t.Fatalf("%+v", v)
	}
	done()
	if s.Snapshot().TCP != 0 {
		t.Fatal("closed connection remains active")
	}
}
func TestConcurrentAndBounded(t *testing.T) {
	s := New()
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := 0; j < 100; j++ {
				done := s.Begin("1.1.1.1", "example.com:443", "tcp")
				done()
				done()
			}
		}()
	}
	wg.Wait()
	v := s.Snapshot()
	if v.TCP != 0 || v.TCPRows[0].Count != 2000 {
		t.Fatalf("%+v", v)
	}
	s.entries = maxEntries
	done := s.Begin("8.8.8.8", "new.example:443", "udp")
	v = s.Snapshot()
	if !v.Truncated || v.SourceIPs != 1 || v.UDP != 1 {
		t.Fatalf("%+v", v)
	}
	done()
}
func TestHistorySurvivesRestartWithoutActiveConnections(t *testing.T) {
	now := time.Date(2026, 9, 24, 0, 0, 0, 0, time.UTC)
	s := New()
	s.now = func() time.Time { return now }
	path := t.TempDir() + "/history.json"
	if err := s.Restore(path); err != nil {
		t.Fatal(err)
	}
	done := s.Begin("1.1.1.1", "example.com:443", "tcp")
	now = now.Add(time.Minute)
	done()
	s.Snapshot()
	if err := s.Save(); err != nil {
		t.Fatal(err)
	}
	restarted := New()
	restarted.now = func() time.Time { return now }
	if err := restarted.Restore(path); err != nil {
		t.Fatal(err)
	}
	v := restarted.Snapshot()
	if v.TCP != 0 || v.TCPRows[0].Count != 1 || v.TCPRows[0].Seconds != 60 {
		t.Fatalf("%+v", v)
	}
}
