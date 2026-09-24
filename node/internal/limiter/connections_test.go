package limiter

import (
	"github.com/shini74744/DBoard/node/internal/model"
	"sync"
	"sync/atomic"
	"testing"
)

func TestConnectionGateConcurrentAdmissionAndIsolation(t *testing.T) {
	g := NewConnectionGate()
	g.Update([]model.UserSpec{{ID: 1, ConnectionLimit: 7}, {ID: 2, ConnectionLimit: 1}})
	var admitted atomic.Int32
	var wg sync.WaitGroup
	releaseAll := make(chan struct{})
	var ready sync.WaitGroup
	ready.Add(100)
	releaseAll = make(chan struct{})
	admitted.Store(0)
	for i := 0; i < 100; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			release, ok := g.Acquire(1)
			if ok {
				admitted.Add(1)
			}
			ready.Done()
			<-releaseAll
			if ok {
				release()
				release()
			}
		}()
	}
	ready.Wait()
	other, ok := g.Acquire(2)
	if !ok {
		t.Fatal("another package must have independent slots")
	}
	other()
	if admitted.Load() != 7 {
		t.Fatalf("admitted %d", admitted.Load())
	}
	close(releaseAll)
	wg.Wait()
	if len(g.active) != 0 {
		t.Fatal("reservation leaked")
	}
}
func TestConnectionGateLoweringLimitCountsExistingUnlimitedSessions(t *testing.T) {
	g := NewConnectionGate()
	first, _ := g.Acquire(41)
	second, _ := g.Acquire(41)
	g.Update([]model.UserSpec{{ID: 41, ConnectionLimit: 1}})
	if _, ok := g.Acquire(41); ok {
		t.Fatal("lowered limit ignored existing sessions")
	}
	first()
	if _, ok := g.Acquire(41); ok {
		t.Fatal("at capacity must reject")
	}
	second()
	release, ok := g.Acquire(41)
	if !ok {
		t.Fatal("closed slots must be reusable")
	}
	release()
	g.Update([]model.UserSpec{{ID: 41, ConnectionLimit: 0}})
	for i := 0; i < 20; i++ {
		release, ok := g.Acquire(41)
		if !ok {
			t.Fatal("zero is unlimited")
		}
		release()
	}
}
