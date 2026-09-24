package limiter

import (
	"github.com/shini74744/DBoard/node/internal/model"
	"sync"
)

// ConnectionGate is shared by successive kernel instances of one node.
// TCP connections and UDP sessions reserve one slot for their full lifetime.
// Lowering a limit rejects new admissions without terminating existing sessions.
type ConnectionGate struct {
	mu     sync.Mutex
	limits map[int]int
	active map[int]int
}

func NewConnectionGate() *ConnectionGate {
	return &ConnectionGate{limits: map[int]int{}, active: map[int]int{}}
}
func (g *ConnectionGate) Update(users []model.UserSpec) {
	if g == nil {
		return
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	g.limits = make(map[int]int, len(users))
	for _, u := range users {
		if u.ConnectionLimit > 0 {
			g.limits[u.ID] = u.ConnectionLimit
		}
	}
}
func (g *ConnectionGate) Acquire(uid int) (func(), bool) {
	if g == nil || uid <= 0 {
		return func() {}, true
	}
	g.mu.Lock()
	if limit := g.limits[uid]; limit > 0 && g.active[uid] >= limit {
		g.mu.Unlock()
		return nil, false
	}
	g.active[uid]++
	g.mu.Unlock()
	var once sync.Once
	return func() {
		once.Do(func() {
			g.mu.Lock()
			defer g.mu.Unlock()
			g.active[uid]--
			if g.active[uid] <= 0 {
				delete(g.active, uid)
			}
		})
	}, true
}
