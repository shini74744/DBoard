package balance

import (
	"context"
	"errors"
	"math/rand/v2"
	"strings"
	"sync"
	"time"
)

var ErrNoHealthyMember = errors.New("no healthy balancer member or fallback; traffic not sent direct")
var ErrClosed = errors.New("balancer closed")

type Probe func(context.Context, string) (time.Duration, error)
type Supports func(tag, network string) bool

type MemberState struct {
	Tag       string        `json:"tag"`
	Known     bool          `json:"known"`
	Healthy   bool          `json:"healthy"`
	Active    uint64        `json:"active"`
	RTT       time.Duration `json:"rtt"`
	Failures  int           `json:"failures"`
	LastProbe time.Time     `json:"last_probe"`
}

type Group struct {
	mu              sync.Mutex
	cfg             Config
	members         []string
	states          map[string]*MemberState
	probe           Probe
	supports        Supports
	cursor          uint64
	selected        string
	started, closed bool
	cancel          context.CancelFunc
	done            chan struct{}
}

func New(c Config, probe Probe, supports Supports) (*Group, error) {
	c, err := c.Normalize()
	if err != nil {
		return nil, err
	}
	g := &Group{cfg: c, members: c.Members, states: map[string]*MemberState{}, probe: probe, supports: supports}
	for _, tag := range c.Members {
		g.states[tag] = &MemberState{Tag: tag}
	}
	if c.Fallback != "" && g.states[c.Fallback] == nil {
		g.states[c.Fallback] = &MemberState{Tag: c.Fallback}
	}
	return g, nil
}

// Acquire reserves one member for the lifetime of a TCP connection or UDP
// association. Existing sessions never migrate when probe results change.
func (g *Group) Acquire(network string, exclude map[string]bool) (*Lease, error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.closed {
		return nil, ErrClosed
	}
	network = strings.TrimSuffix(strings.TrimSuffix(network, "4"), "6")
	available := func(tag string) bool {
		st := g.states[tag]
		return st != nil && !exclude[tag] && (!st.Known || st.Healthy) && (g.supports == nil || g.supports(tag, network))
	}
	candidates := make([]string, 0, len(g.members))
	// Rotating start provides fair tie-breaking for all deterministic strategies.
	for i := 0; i < len(g.members); i++ {
		tag := g.members[(int(g.cursor%uint64(len(g.members)))+i)%len(g.members)]
		if available(tag) {
			candidates = append(candidates, tag)
		}
	}
	var tag string
	if len(candidates) == 0 {
		if g.cfg.Fallback == "" || !available(g.cfg.Fallback) {
			return nil, ErrNoHealthyMember
		}
		tag = g.cfg.Fallback
	} else {
		tag = candidates[0]
		switch g.cfg.Strategy {
		case "random":
			tag = candidates[rand.IntN(len(candidates))]
		case "latency":
			for _, t := range candidates[1:] {
				if betterLatency(g.states[t], g.states[tag]) {
					tag = t
				}
			}
		case "least_load":
			for _, t := range candidates[1:] {
				a, b := g.states[t], g.states[tag]
				if a.Active < b.Active || (a.Active == b.Active && betterLatency(a, b)) {
					tag = t
				}
			}
		}
	}
	g.cursor++
	g.states[tag].Active++
	g.selected = tag
	return &Lease{Tag: tag, group: g}, nil
}

func betterLatency(a, b *MemberState) bool {
	if a.RTT <= 0 {
		return false
	}
	return b.RTT <= 0 || a.RTT < b.RTT
}

type Lease struct {
	Tag   string
	group *Group
	once  sync.Once
}

func (l *Lease) Release() {
	if l == nil {
		return
	}
	l.once.Do(func() {
		l.group.mu.Lock()
		defer l.group.mu.Unlock()
		if s := l.group.states[l.Tag]; s != nil && s.Active > 0 {
			s.Active--
		}
	})
}

// RecordProbe is used only for a dedicated health probe, not arbitrary user
// requests: an unavailable destination must not disable a healthy proxy.
func (g *Group) RecordProbe(tag string, rtt time.Duration, err error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	s := g.states[tag]
	if s == nil || g.closed {
		return
	}
	s.LastProbe = time.Now()
	if err != nil {
		s.Failures++
		if s.Failures >= g.cfg.FailureThreshold {
			s.Known = true
			s.Healthy = false
		}
		return
	}
	s.Known = true
	s.Healthy = true
	s.Failures = 0
	if rtt > 0 {
		if s.RTT == 0 {
			s.RTT = rtt
		} else {
			s.RTT = (s.RTT*3 + rtt) / 4
		}
	}
}

func (g *Group) Snapshot() []MemberState {
	g.mu.Lock()
	defer g.mu.Unlock()
	out := make([]MemberState, 0, len(g.states))
	for _, tag := range g.members {
		out = append(out, *g.states[tag])
	}
	if g.cfg.Fallback != "" {
		in := false
		for _, tag := range g.members {
			if tag == g.cfg.Fallback {
				in = true
			}
		}
		if !in {
			out = append(out, *g.states[g.cfg.Fallback])
		}
	}
	return out
}
func (g *Group) Now() string { g.mu.Lock(); defer g.mu.Unlock(); return g.selected }

func (g *Group) Start(parent context.Context) error {
	g.mu.Lock()
	defer g.mu.Unlock()
	if g.closed {
		return ErrClosed
	}
	if g.started {
		return nil
	}
	g.started = true
	ctx, cancel := context.WithCancel(parent)
	g.cancel = cancel
	g.done = make(chan struct{})
	go func() {
		defer close(g.done)
		if g.probe == nil {
			<-ctx.Done()
			return
		}
		g.probeRound(ctx)
		timer := time.NewTimer(g.cfg.ProbeInterval)
		defer timer.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-timer.C:
				g.probeRound(ctx)
				timer.Reset(g.cfg.ProbeInterval)
			}
		}
	}()
	return nil
}
func (g *Group) probeRound(ctx context.Context) {
	tags := append([]string(nil), g.members...)
	if g.cfg.Fallback != "" {
		found := false
		for _, tag := range tags {
			if tag == g.cfg.Fallback {
				found = true
			}
		}
		if !found {
			tags = append(tags, g.cfg.Fallback)
		}
	}
	sem := make(chan struct{}, 4)
	var wg sync.WaitGroup
	for _, tag := range tags {
		select {
		case <-ctx.Done():
			wg.Wait()
			return
		case sem <- struct{}{}:
		}
		wg.Add(1)
		go func(tag string) {
			defer wg.Done()
			defer func() { <-sem }()
			pctx, cancel := context.WithTimeout(ctx, g.cfg.ProbeTimeout)
			defer cancel()
			rtt, err := g.probe(pctx, tag)
			if ctx.Err() == nil {
				g.RecordProbe(tag, rtt, err)
			}
		}(tag)
	}
	wg.Wait()
}
func (g *Group) Close() error {
	g.mu.Lock()
	g.closed = true
	cancel, done := g.cancel, g.done
	g.mu.Unlock()
	if cancel != nil {
		cancel()
	}
	if done != nil {
		<-done
	}
	return nil
}
