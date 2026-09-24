package kernel

import (
	"github.com/google/uuid"
	"sync"
	"sync/atomic"
)

// OutboundTrafficSnapshot is cumulative for one kernel lifetime. Repeated or
// out-of-order reports are deduplicated by the panel using session + outbound ID.
type OutboundTrafficSnapshot struct {
	Session string           `json:"session"`
	Traffic map[int][2]int64 `json:"traffic"`
}
type OutboundTrafficProvider interface {
	GetOutboundTraffic() OutboundTrafficSnapshot
}
type OutboundCounter struct {
	Upload   atomic.Int64
	Download atomic.Int64
}
type OutboundTrafficStore struct {
	mu       sync.Mutex
	session  string
	counters map[int]*OutboundCounter
}

func (s *OutboundTrafficStore) Counter(id int) *OutboundCounter {
	if id <= 0 {
		return nil
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.counters == nil {
		s.counters = make(map[int]*OutboundCounter)
	}
	if s.counters[id] == nil {
		s.counters[id] = &OutboundCounter{}
	}
	return s.counters[id]
}
func (s *OutboundTrafficStore) Snapshot() OutboundTrafficSnapshot {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.session == "" {
		s.session = uuid.NewString()
	}
	out := OutboundTrafficSnapshot{Session: s.session, Traffic: make(map[int][2]int64)}
	for id, c := range s.counters {
		out.Traffic[id] = [2]int64{c.Upload.Load(), c.Download.Load()}
	}
	return out
}
