package main

import (
	"context"
	"net"
	"net/http"
	"sync"
	"time"
)

type dashboardConnKey struct{}
type dashboardConn struct {
	conn        net.Conn
	headersRead sync.Once
}

func newDashboardHTTPServer(handler http.Handler) *http.Server {
	s := &http.Server{ReadHeaderTimeout: 5 * time.Second}
	s.ConnContext = func(ctx context.Context, conn net.Conn) context.Context {
		return context.WithValue(ctx, dashboardConnKey{}, &dashboardConn{conn: conn})
	}
	s.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Go 1.26.6 leaves the initial ReadHeaderTimeout on native h2c connections.
		// Headers have now arrived: clear that connection deadline once so long-lived
		// gRPC streams survive. HTTP/1 header protection remains unchanged.
		if r.ProtoMajor == 2 {
			if c, ok := r.Context().Value(dashboardConnKey{}).(*dashboardConn); ok {
				c.headersRead.Do(func() { _ = c.conn.SetReadDeadline(time.Time{}) })
			}
		}
		handler.ServeHTTP(w, r)
	})
	s.Protocols = new(http.Protocols)
	s.Protocols.SetHTTP1(true)
	s.Protocols.SetUnencryptedHTTP2(true)
	return s
}
