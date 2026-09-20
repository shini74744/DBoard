package balance

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"time"
)

// HTTPProbe performs a bounded end-to-end probe through the specific member.
// It never inherits HTTP_PROXY from the host and does not follow redirects.
func HTTPProbe(ctx context.Context, dial func(context.Context, string, string) (net.Conn, error), url string) (time.Duration, error) {
	start := time.Now()
	transport := &http.Transport{DialContext: dial, DisableKeepAlives: true, MaxResponseHeaderBytes: 16 << 10, TLSHandshakeTimeout: 5 * time.Second}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, err
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return 0, fmt.Errorf("probe HTTP status %d", resp.StatusCode)
	}
	return time.Since(start), nil
}

type Conn struct {
	net.Conn
	Lease *Lease
}

func (c *Conn) Close() error { defer c.Lease.Release(); return c.Conn.Close() }
func (c *Conn) CloseWrite() error {
	if v, ok := c.Conn.(interface{ CloseWrite() error }); ok {
		return v.CloseWrite()
	}
	return nil
}
func (c *Conn) CloseRead() error {
	if v, ok := c.Conn.(interface{ CloseRead() error }); ok {
		return v.CloseRead()
	}
	return nil
}

type PacketConn struct {
	net.PacketConn
	Lease *Lease
}

func (c *PacketConn) Close() error { defer c.Lease.Release(); return c.PacketConn.Close() }
