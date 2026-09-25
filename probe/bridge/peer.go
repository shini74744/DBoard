package bridge

import (
	"context"
	"errors"
	"github.com/gorilla/websocket"
	"sync"
	"time"
)

type Peer struct {
	conn    *websocket.Conn
	writeMu sync.Mutex
	mu      sync.Mutex
	pending map[string]chan Frame
	streams map[string]chan Frame
	done    chan struct{}
	once    sync.Once
}

func NewPeer(c *websocket.Conn) *Peer {
	return &Peer{conn: c, pending: map[string]chan Frame{}, streams: map[string]chan Frame{}, done: make(chan struct{})}
}
func (p *Peer) Close() { p.once.Do(func() { close(p.done); p.conn.Close() }) }
func (p *Peer) Send(f Frame) error {
	p.writeMu.Lock()
	defer p.writeMu.Unlock()
	p.conn.SetWriteDeadline(time.Now().Add(15 * time.Second))
	return p.conn.WriteJSON(f)
}
func (p *Peer) Serve(ctx context.Context, handler func(Frame)) {
	p.conn.SetReadLimit(2 * MaxBody)
	p.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	p.conn.SetPongHandler(func(string) error { return p.conn.SetReadDeadline(time.Now().Add(60 * time.Second)) })
	go func() {
		t := time.NewTicker(20 * time.Second)
		defer t.Stop()
		for {
			select {
			case <-p.done:
				return
			case <-t.C:
				if p.conn.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second)) != nil {
					p.Close()
					return
				}
			}
		}
	}()
	go func() {
		select {
		case <-ctx.Done():
			p.Close()
		case <-p.done:
		}
	}()
	defer p.Close()
	for {
		var f Frame
		if e := p.conn.ReadJSON(&f); e != nil {
			return
		}
		p.mu.Lock()
		ch := p.pending[f.ID]
		if ch == nil {
			ch = p.streams[f.ID]
		}
		p.mu.Unlock()
		if ch != nil {
			select {
			case ch <- f:
			case <-p.done:
				return
			default:
				p.Close()
				return
			}
		} else if handler != nil {
			handler(f)
		}
	}
}
func (p *Peer) Call(ctx context.Context, f Frame) (Frame, error) {
	if f.ID == "" {
		f.ID = ID()
	}
	ch := make(chan Frame, 1)
	p.mu.Lock()
	p.pending[f.ID] = ch
	p.mu.Unlock()
	defer func() { p.mu.Lock(); delete(p.pending, f.ID); p.mu.Unlock() }()
	if e := p.Send(f); e != nil {
		return Frame{}, e
	}
	select {
	case r := <-ch:
		return r, nil
	case <-ctx.Done():
		return Frame{}, ctx.Err()
	case <-p.done:
		return Frame{}, errors.New("probe connector disconnected")
	}
}
func (p *Peer) Stream(id string) (<-chan Frame, func()) {
	ch := make(chan Frame, 64)
	p.mu.Lock()
	p.streams[id] = ch
	p.mu.Unlock()
	return ch, func() { p.mu.Lock(); delete(p.streams, id); p.mu.Unlock() }
}
