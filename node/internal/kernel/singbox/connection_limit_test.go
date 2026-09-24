package singbox

import (
	"context"
	N "github.com/sagernet/sing/common/network"
	"github.com/shini74744/DBoard/node/internal/model"
	"testing"
)

type limitTestPacket struct {
	N.PacketConn
	closed bool
}

func (p *limitTestPacket) Close() error { p.closed = true; return nil }
func TestConnectionLimitCombinesTCPUDPAndSurvivesTrackerReplacement(t *testing.T) {
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1, "uuid-2": 2})
	tracker.connectionGate.Update([]model.UserSpec{{ID: 1, ConnectionLimit: 1}, {ID: 2, ConnectionLimit: 1}})
	ctx := context.Background()
	meta := testInboundContext("uuid-1", "1.1.1.1")
	tcp := tracker.RoutedConnection(ctx, &testConn{}, meta, nil, nil)
	rejected := &limitTestPacket{}
	tracker.RoutedPacketConnection(ctx, rejected, meta, nil, nil)
	if !rejected.closed {
		t.Fatal("UDP must share the TCP limit")
	}
	other := tracker.RoutedConnection(ctx, &testConn{}, testInboundContext("uuid-2", "1.1.1.1"), nil, nil)
	defer other.Close()
	// A replacement tracker on the same node cannot ignore draining old sessions.
	replacement := NewConnTracker(0)
	replacement.connectionGate = tracker.connectionGate
	replacement.SetUserMap(map[string]int{"uuid-1": 1})
	blocked := &testConn{}
	replacement.RoutedConnection(ctx, blocked, meta, nil, nil)
	if !blocked.closed {
		t.Fatal("replacement lost existing reservations")
	}
	tcp.Close()
	tcp.Close()
	packet := &limitTestPacket{}
	udp := replacement.RoutedPacketConnection(ctx, packet, meta, nil, nil)
	if packet.closed {
		t.Fatal("released TCP slot unavailable to UDP")
	}
	blocked = &testConn{}
	replacement.RoutedConnection(ctx, blocked, meta, nil, nil)
	if !blocked.closed {
		t.Fatal("TCP must share the UDP limit")
	}
	udp.Close()
	udp.Close()
	if release, ok := tracker.connectionGate.Acquire(1); !ok {
		t.Fatal("UDP reservation leaked")
	} else {
		release()
	}
}
