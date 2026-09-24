package singbox

import (
	"context"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/log"
	SM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"github.com/shini74744/DBoard/node/internal/kernel"
	"io"
	"net"
)

// Wrap managed proxy outbounds at the dialer boundary. This counts the actual
// balancer member and each hop of a chain, including TCP and UDP. Built-in
// direct/block and untracked local configuration are left unchanged.
type trafficOutboundRegistry struct {
	adapter.OutboundRegistry
	ids   map[string]int
	store *kernel.OutboundTrafficStore
}

func (r *trafficOutboundRegistry) CreateOutbound(ctx context.Context, router adapter.Router, logger log.ContextLogger, tag, typ string, options any) (adapter.Outbound, error) {
	out, err := r.OutboundRegistry.CreateOutbound(ctx, router, logger, tag, typ, options)
	if err != nil {
		return nil, err
	}
	if counter := r.store.Counter(r.ids[tag]); counter != nil {
		return &trafficOutbound{Outbound: out, counter: counter}, nil
	}
	return out, nil
}

type trafficOutbound struct {
	adapter.Outbound
	counter *kernel.OutboundCounter
}

func (o *trafficOutbound) Start(stage adapter.StartStage) error {
	return adapter.LegacyStart(o.Outbound, stage)
}
func (o *trafficOutbound) Close() error {
	if c, ok := o.Outbound.(io.Closer); ok {
		return c.Close()
	}
	return nil
}
func (o *trafficOutbound) DialContext(ctx context.Context, network string, dest SM.Socksaddr) (net.Conn, error) {
	c, err := o.Outbound.DialContext(ctx, network, dest)
	if err != nil {
		return nil, err
	}
	return &outboundCountConn{Conn: c, counter: o.counter}, nil
}
func (o *trafficOutbound) ListenPacket(ctx context.Context, dest SM.Socksaddr) (net.PacketConn, error) {
	c, err := o.Outbound.ListenPacket(ctx, dest)
	if err != nil {
		return nil, err
	}
	return &outboundCountPacketConn{PacketConn: c, counter: o.counter}, nil
}

type outboundCountConn struct {
	net.Conn
	counter *kernel.OutboundCounter
}

func (c *outboundCountConn) Read(p []byte) (int, error) {
	n, e := c.Conn.Read(p)
	c.counter.Download.Add(int64(n))
	return n, e
}
func (c *outboundCountConn) Write(p []byte) (int, error) {
	n, e := c.Conn.Write(p)
	c.counter.Upload.Add(int64(n))
	return n, e
}
func (c *outboundCountConn) UnwrapReader() (io.Reader, []N.CountFunc) {
	return c.Conn, []N.CountFunc{func(n int64) { c.counter.Download.Add(n) }}
}
func (c *outboundCountConn) UnwrapWriter() (io.Writer, []N.CountFunc) {
	return c.Conn, []N.CountFunc{func(n int64) { c.counter.Upload.Add(n) }}
}
func (c *outboundCountConn) CloseWrite() error {
	if v, ok := c.Conn.(interface{ CloseWrite() error }); ok {
		return v.CloseWrite()
	}
	return nil
}
func (c *outboundCountConn) CloseRead() error {
	if v, ok := c.Conn.(interface{ CloseRead() error }); ok {
		return v.CloseRead()
	}
	return nil
}

type outboundCountPacketConn struct {
	net.PacketConn
	counter *kernel.OutboundCounter
}

func (c *outboundCountPacketConn) ReadFrom(p []byte) (int, net.Addr, error) {
	n, a, e := c.PacketConn.ReadFrom(p)
	c.counter.Download.Add(int64(n))
	return n, a, e
}
func (c *outboundCountPacketConn) WriteTo(p []byte, a net.Addr) (int, error) {
	n, e := c.PacketConn.WriteTo(p, a)
	c.counter.Upload.Add(int64(n))
	return n, e
}
func (s *SingBox) GetOutboundTraffic() kernel.OutboundTrafficSnapshot {
	return s.outboundTraffic.Snapshot()
}
