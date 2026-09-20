package balance

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func newTestGroup(t *testing.T, strategy string) *Group {
	t.Helper()
	g, err := New(Config{Strategy: strategy, Members: []string{"a", "b"}, Fallback: "f"}, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { g.Close() })
	return g
}
func TestLegacyStrategyAliases(t *testing.T) {
	for a, b := range map[string]string{"roundRobin": "round_robin", "round_robin": "round_robin", "leastPing": "latency", "latency": "latency", "leastLoad": "least_load", "least_load": "least_load", "random": "random"} {
		got, err := NormalizeStrategy(a)
		if err != nil || got != b {
			t.Fatalf("%s -> %s, %v", a, got, err)
		}
	}
}
func TestStrategiesAndLeaseAccounting(t *testing.T) {
	for _, strategy := range []string{"random", "round_robin", "latency", "least_load"} {
		t.Run(strategy, func(t *testing.T) {
			g := newTestGroup(t, strategy)
			g.RecordProbe("a", 100*time.Millisecond, nil)
			g.RecordProbe("b", time.Millisecond, nil)
			a, err := g.Acquire("tcp", nil)
			if err != nil {
				t.Fatal(err)
			}
			if strategy == "latency" && a.Tag != "b" {
				t.Fatal("did not prefer lower latency")
			}
			b, err := g.Acquire("tcp", nil)
			if err != nil {
				t.Fatal(err)
			}
			if strategy == "least_load" && a.Tag == b.Tag {
				t.Fatal("ignored outstanding lease")
			}
			a.Release()
			a.Release()
			b.Release()
			for _, s := range g.Snapshot() {
				if s.Active != 0 {
					t.Fatalf("leaked active lease %+v", s)
				}
			}
		})
	}
}
func TestHealthFailoverAndFailClosed(t *testing.T) {
	g := newTestGroup(t, "round_robin")
	fail := errors.New("probe failed")
	for _, tag := range []string{"a", "b"} {
		g.RecordProbe(tag, 0, fail)
		g.RecordProbe(tag, 0, fail)
	}
	lease, err := g.Acquire("tcp", nil)
	if err != nil || lease.Tag != "f" {
		t.Fatalf("fallback missing: %v %v", lease, err)
	}
	lease.Release()
	g.RecordProbe("f", 0, fail)
	g.RecordProbe("f", 0, fail)
	if _, err = g.Acquire("tcp", nil); !errors.Is(err, ErrNoHealthyMember) {
		t.Fatalf("must fail closed: %v", err)
	}
	g.RecordProbe("b", time.Millisecond, nil)
	lease, err = g.Acquire("udp", nil)
	if err != nil || lease.Tag != "b" {
		t.Fatalf("recovery failed %v %v", lease, err)
	}
	lease.Release()
}
func TestNetworkEligibilityAndNoReplay(t *testing.T) {
	g, err := New(Config{Members: []string{"tcp-only", "udp"}}, nil, func(tag, network string) bool { return tag != "tcp-only" || network == "tcp" })
	if err != nil {
		t.Fatal(err)
	}
	defer g.Close()
	lease, err := g.Acquire("udp", nil)
	if err != nil || lease.Tag != "udp" {
		t.Fatalf("wrong UDP candidate %v %v", lease, err)
	}
	lease.Release()
	if _, err = g.Acquire("udp", map[string]bool{"udp": true}); !errors.Is(err, ErrNoHealthyMember) {
		t.Fatalf("retry bypassed network eligibility: %v", err)
	}
}
func TestConcurrentAcquireRelease(t *testing.T) {
	g := newTestGroup(t, "least_load")
	var wg sync.WaitGroup
	for i := 0; i < 32; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := 0; j < 500; j++ {
				lease, err := g.Acquire("tcp", nil)
				if err != nil {
					t.Error(err)
					return
				}
				lease.Release()
				lease.Release()
			}
		}()
	}
	wg.Wait()
	for _, s := range g.Snapshot() {
		if s.Active != 0 {
			t.Fatalf("bad active count %v", s)
		}
	}
}
func TestProbeCancellationAndIdempotentClose(t *testing.T) {
	var started atomic.Int32
	g, err := New(Config{Members: []string{"a", "b"}, ProbeInterval: time.Millisecond, ProbeTimeout: time.Second}, func(ctx context.Context, tag string) (time.Duration, error) {
		started.Add(1)
		<-ctx.Done()
		return 0, ctx.Err()
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err = g.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(time.Second)
	for started.Load() < 2 && time.Now().Before(deadline) {
		time.Sleep(time.Millisecond)
	}
	done := make(chan struct{})
	go func() { g.Close(); g.Close(); close(done) }()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("probe cancellation leaked")
	}
	if _, err = g.Acquire("tcp", nil); !errors.Is(err, ErrClosed) {
		t.Fatalf("closed group accepted sessions: %v", err)
	}
}
