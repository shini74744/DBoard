package canceler

import (
	"context"
	"net"
	"sync"
	"time"

	"github.com/sagernet/sing/common"
	"github.com/sagernet/sing/common/buf"
	E "github.com/sagernet/sing/common/exceptions"
	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
)

type TimeoutPacketConn struct {
	N.PacketConn
	activityMu sync.RWMutex
	timeout    time.Duration
	cancel     common.ContextCancelCauseFunc
	active     time.Time
}

func NewTimeoutPacketConn(ctx context.Context, conn N.PacketConn, timeout time.Duration) (context.Context, PacketConn) {
	ctx, cancel := common.ContextWithCancelCause(ctx)
	return ctx, &TimeoutPacketConn{
		PacketConn: conn,
		timeout:    timeout,
		cancel:     cancel,
	}
}

func (c *TimeoutPacketConn) ReadPacket(buffer *buf.Buffer) (destination M.Socksaddr, err error) {
	for {
		err = c.PacketConn.SetReadDeadline(time.Now().Add(c.Timeout()))
		if err != nil {
			return
		}
		destination, err = c.PacketConn.ReadPacket(buffer)
		if err == nil {
			c.markActive()
			return
		} else if E.IsTimeout(err) {
			if time.Since(c.lastActivity()) > c.Timeout() {
				c.cancel(err)
				return
			}
		} else {
			return
		}
	}
}

func (c *TimeoutPacketConn) WritePacket(buffer *buf.Buffer, destination M.Socksaddr) error {
	err := c.PacketConn.WritePacket(buffer, destination)
	if err == nil {
		c.markActive()
	}
	return err
}

func (c *TimeoutPacketConn) Timeout() time.Duration {
	c.activityMu.RLock()
	defer c.activityMu.RUnlock()
	return c.timeout
}

func (c *TimeoutPacketConn) SetTimeout(timeout time.Duration) bool {
	c.activityMu.Lock()
	c.timeout = timeout
	c.activityMu.Unlock()
	return c.PacketConn.SetReadDeadline(time.Now()) == nil
}

func (c *TimeoutPacketConn) Close() error {
	c.cancel(net.ErrClosed)
	return c.PacketConn.Close()
}

func (c *TimeoutPacketConn) Upstream() any {
	return c.PacketConn
}

func (c *TimeoutPacketConn) markActive() {
	c.activityMu.Lock()
	c.active = time.Now()
	c.activityMu.Unlock()
}
func (c *TimeoutPacketConn) lastActivity() time.Time {
	c.activityMu.RLock()
	defer c.activityMu.RUnlock()
	return c.active
}
