package xray

import (
	"context"
	"fmt"
	"net"
	"strings"
	"sync"
	"time"

	"github.com/shini74744/DBoard/node/internal/balance"
	"github.com/shini74744/DBoard/node/internal/model"
	"github.com/xtls/xray-core/common"
	XN "github.com/xtls/xray-core/common/net"
	"github.com/xtls/xray-core/common/net/cnc"
	XS "github.com/xtls/xray-core/common/serial"
	"github.com/xtls/xray-core/common/session"
	"github.com/xtls/xray-core/core"
	"github.com/xtls/xray-core/features/outbound"
	"github.com/xtls/xray-core/transport"
	"github.com/xtls/xray-core/transport/pipe"
)

// universalHandler delegates to an existing Xray outbound Handler, retaining
// the original transport.Link (including UDP packet metadata and user limits).
// It does not run another proxy core or open loopback SOCKS ports.
type universalHandler struct {
	tag     string
	manager outbound.Manager
	group   *balance.Group
	config  balance.Config
}

var _ outbound.Handler = (*universalHandler)(nil)

func installUniversalBalancers(instance *core.Instance, items []model.CustomBalancer) error {
	if len(items) == 0 {
		return nil
	}
	manager, ok := instance.GetFeature(outbound.ManagerType()).(outbound.Manager)
	if !ok {
		return fmt.Errorf("Xray outbound manager is unavailable")
	}
	for _, item := range items {
		cfg := item.BalanceConfig()
		h := &universalHandler{tag: item.Tag, manager: manager}
		normalized, err := cfg.Normalize()
		if err != nil {
			return err
		}
		h.config = normalized
		h.group, err = balance.New(normalized, h.probe, func(tag, network string) bool {
			handler := manager.GetHandler(tag)
			if handler == nil {
				return false
			}
			settings := handler.ProxySettings()
			if settings != nil && strings.Contains(settings.Type, "blackhole") {
				return false
			}
			return network != "udp" || settings == nil || !strings.Contains(settings.Type, ".http.")
		})
		if err != nil {
			return err
		}
		// The JSON representation uses a fail-closed placeholder for the group.
		// Replace only that placeholder, never a real user-defined outbound.
		if old := manager.GetHandler(item.Tag); old != nil {
			if settings := old.ProxySettings(); settings == nil || !strings.Contains(settings.Type, "blackhole") {
				return fmt.Errorf("balancer tag collision %q", item.Tag)
			}
			if err := manager.RemoveHandler(context.Background(), item.Tag); err != nil {
				return err
			}
		}
		if err := manager.AddHandler(context.Background(), h); err != nil {
			return err
		}
	}
	return nil
}
func (h *universalHandler) Tag() string                      { return h.tag }
func (h *universalHandler) SenderSettings() *XS.TypedMessage { return nil }
func (h *universalHandler) ProxySettings() *XS.TypedMessage  { return nil }
func (h *universalHandler) Start() error                     { return h.group.Start(context.Background()) }
func (h *universalHandler) Close() error                     { return h.group.Close() }

type balanceStackKey struct{}

func (h *universalHandler) Dispatch(ctx context.Context, link *transport.Link) {
	reject := func(err error) {
		session.SubmitOutboundErrorToOriginator(ctx, err)
		common.Interrupt(link.Reader)
		common.Interrupt(link.Writer)
	}
	stack, _ := ctx.Value(balanceStackKey{}).([]string)
	for _, tag := range stack {
		if tag == h.tag {
			reject(fmt.Errorf("balancer recursion %q", tag))
			return
		}
	}
	outbounds := session.OutboundsFromContext(ctx)
	if len(outbounds) == 0 || outbounds[len(outbounds)-1] == nil {
		reject(fmt.Errorf("missing outbound destination"))
		return
	}
	current := *outbounds[len(outbounds)-1]
	network := "tcp"
	if current.Target.Network == XN.Network_UDP {
		network = "udp"
	}
	lease, err := h.group.Acquire(network, nil)
	if err != nil {
		reject(err)
		return
	}
	defer lease.Release()
	peer := h.manager.GetHandler(lease.Tag)
	if peer == nil {
		reject(fmt.Errorf("balancer member %q disappeared", lease.Tag))
		return
	}
	current.Tag = lease.Tag
	copied := append([]*session.Outbound(nil), outbounds...)
	copied[len(copied)-1] = &current
	ctx = session.ContextWithOutbounds(ctx, copied)
	ctx = context.WithValue(ctx, balanceStackKey{}, append(append([]string(nil), stack...), h.tag))
	// No retry after handing a Link to a member: replaying arbitrary TCP streams
	// or switching a live UDP association could corrupt application sessions.
	peer.Dispatch(ctx, link)
}

func (h *universalHandler) probe(ctx context.Context, tag string) (time.Duration, error) {
	return balance.HTTPProbe(ctx, func(ctx context.Context, network, address string) (net.Conn, error) {
		return dialTagged(ctx, h.manager, tag, address)
	}, h.config.ProbeURL)
}

type ownedProbeConn struct {
	net.Conn
	cancel context.CancelFunc
	once   sync.Once
}

func (c *ownedProbeConn) Close() error {
	var err error
	c.once.Do(func() { c.cancel(); err = c.Conn.Close() })
	return err
}

// End-to-end probes dispatch directly to the chosen member, not to the router
// (which could send the probe back to this group).
func dialTagged(ctx context.Context, manager outbound.Manager, tag, address string) (net.Conn, error) {
	handler := manager.GetHandler(tag)
	if handler == nil {
		return nil, fmt.Errorf("missing outbound %s", tag)
	}
	dest, err := XN.ParseDestination("tcp:" + address)
	if err != nil {
		return nil, err
	}
	ctx, cancel := context.WithCancel(ctx)
	reqReader, reqWriter := pipe.New(pipe.WithSizeLimit(64 << 10))
	respReader, respWriter := pipe.New(pipe.WithSizeLimit(64 << 10))
	c := &ownedProbeConn{Conn: cnc.NewConnection(cnc.ConnectionInputMulti(reqWriter), cnc.ConnectionOutputMulti(respReader)), cancel: cancel}
	context.AfterFunc(ctx, func() { common.Interrupt(reqReader); common.Interrupt(respWriter); c.Close() })
	ctx = session.ContextWithOutbounds(ctx, []*session.Outbound{{Target: dest, Tag: tag}})
	ctx = session.ContextWithInbound(ctx, &session.Inbound{Tag: "xboard-health-probe"})
	go handler.Dispatch(ctx, &transport.Link{Reader: reqReader, Writer: respWriter})
	return c, nil
}
