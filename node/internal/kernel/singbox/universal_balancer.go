package singbox

import (
	"context"
	"fmt"
	"net"
	"slices"
	"time"

	"github.com/sagernet/sing-box/adapter"
	O "github.com/sagernet/sing-box/adapter/outbound"
	"github.com/sagernet/sing-box/log"
	SM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"github.com/sagernet/sing/service"
	"github.com/shini74744/DBoard/node/internal/balance"
)

const universalBalancerType = "xboard-balancer"

type universalBalancerOptions struct {
	Strategy             string   `json:"strategy"`
	Members              []string `json:"members"`
	Fallback             string   `json:"fallback,omitempty"`
	ProbeURL             string   `json:"probe_url,omitempty"`
	ProbeIntervalSeconds int      `json:"probe_interval_seconds,omitempty"`
	ProbeTimeoutSeconds  int      `json:"probe_timeout_seconds,omitempty"`
}

// Registered on a per-Box registry, never by replacing a global sing-box type.
func registerUniversalBalancer(registry *O.Registry) {
	O.Register[universalBalancerOptions](registry, universalBalancerType, newUniversalBalancer)
}

type universalBalancer struct {
	O.Adapter
	ctx     context.Context
	manager adapter.OutboundManager
	peers   map[string]adapter.Outbound
	group   *balance.Group
	config  balance.Config
}

var _ adapter.Outbound = (*universalBalancer)(nil)

func newUniversalBalancer(ctx context.Context, _ adapter.Router, _ log.ContextLogger, tag string, opts universalBalancerOptions) (adapter.Outbound, error) {
	cfg, err := (balance.Config{Strategy: opts.Strategy, Members: opts.Members, Fallback: opts.Fallback, ProbeURL: opts.ProbeURL,
		ProbeInterval: time.Duration(opts.ProbeIntervalSeconds) * time.Second, ProbeTimeout: time.Duration(opts.ProbeTimeoutSeconds) * time.Second}).Normalize()
	if err != nil {
		return nil, err
	}
	deps := append([]string(nil), cfg.Members...)
	if cfg.Fallback != "" && !slices.Contains(deps, cfg.Fallback) {
		deps = append(deps, cfg.Fallback)
	}
	if slices.Contains(deps, tag) {
		return nil, fmt.Errorf("balancer %s references itself", tag)
	}
	b := &universalBalancer{Adapter: O.NewAdapter(universalBalancerType, tag, []string{N.NetworkTCP, N.NetworkUDP}, deps), ctx: ctx,
		manager: service.FromContext[adapter.OutboundManager](ctx), peers: map[string]adapter.Outbound{}, config: cfg}
	b.group, err = balance.New(cfg, b.probe, func(tag, network string) bool {
		p := b.peers[tag]
		return p != nil && slices.Contains(p.Network(), network)
	})
	return b, err
}

func (b *universalBalancer) Start() error {
	for _, tag := range b.Dependencies() {
		peer, ok := b.manager.Outbound(tag)
		if !ok {
			return fmt.Errorf("balancer %s: missing member %s", b.Tag(), tag)
		}
		b.peers[tag] = peer
	}
	return b.group.Start(b.ctx)
}
func (b *universalBalancer) Close() error  { return b.group.Close() }
func (b *universalBalancer) Now() string   { return b.group.Now() }
func (b *universalBalancer) All() []string { return append([]string(nil), b.config.Members...) }

func (b *universalBalancer) probe(ctx context.Context, tag string) (time.Duration, error) {
	p := b.peers[tag]
	if p == nil {
		return 0, fmt.Errorf("missing probe member %s", tag)
	}
	return balance.HTTPProbe(ctx, func(ctx context.Context, network, address string) (net.Conn, error) {
		return p.DialContext(ctx, network, SM.ParseSocksaddr(address))
	}, b.config.ProbeURL)
}

func (b *universalBalancer) DialContext(ctx context.Context, network string, destination SM.Socksaddr) (net.Conn, error) {
	// Retry only a failed Dial before any user payload is forwarded. A live
	// connection is pinned and never replayed or migrated to another member.
	excluded := map[string]bool{}
	var last error
	for ctx.Err() == nil {
		lease, err := b.group.Acquire(network, excluded)
		if err != nil {
			if last != nil {
				return nil, fmt.Errorf("%w: %v", err, last)
			}
			return nil, err
		}
		conn, err := b.peers[lease.Tag].DialContext(ctx, network, destination)
		if err == nil {
			return &balance.Conn{Conn: conn, Lease: lease}, nil
		}
		excluded[lease.Tag] = true
		lease.Release()
		last = err
	}
	return nil, ctx.Err()
}
func (b *universalBalancer) ListenPacket(ctx context.Context, destination SM.Socksaddr) (net.PacketConn, error) {
	excluded := map[string]bool{}
	var last error
	for ctx.Err() == nil {
		lease, err := b.group.Acquire(N.NetworkUDP, excluded)
		if err != nil {
			if last != nil {
				return nil, fmt.Errorf("%w: %v", err, last)
			}
			return nil, err
		}
		conn, err := b.peers[lease.Tag].ListenPacket(ctx, destination)
		if err == nil {
			return &balance.PacketConn{PacketConn: conn, Lease: lease}, nil
		}
		excluded[lease.Tag] = true
		lease.Release()
		last = err
	}
	return nil, ctx.Err()
}
