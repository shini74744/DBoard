package main

import (
	"bufio"
	"io"
	"net"
	"net/http"
	"strings"
	"testing"
	"time"
)

func TestHTTP2StreamsSurviveHeaderDeadline(t *testing.T) {
	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.ProtoMajor != 2 {
			t.Error("HTTP/2 required")
		}
		w.WriteHeader(200)
		w.(http.Flusher).Flush()
		time.Sleep(250 * time.Millisecond)
		io.WriteString(w, "still-connected")
	})
	s := newDashboardHTTPServer(h)
	s.ReadHeaderTimeout = 50 * time.Millisecond
	l, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer s.Close()
	go s.Serve(l)
	tr := &http.Transport{Protocols: new(http.Protocols)}
	tr.Protocols.SetUnencryptedHTTP2(true)
	defer tr.CloseIdleConnections()
	c := &http.Client{Transport: tr, Timeout: time.Second}
	r, e := c.Get("http://" + l.Addr().String() + "/")
	if e != nil {
		t.Fatal(e)
	}
	defer r.Body.Close()
	b, e := io.ReadAll(r.Body)
	if e != nil || string(b) != "still-connected" {
		t.Fatalf("stream closed after headers: %q %v", b, e)
	}
}
func TestHTTP1IncompleteHeadersStillTimeOut(t *testing.T) {
	s := newDashboardHTTPServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("incomplete request reached handler") }))
	s.ReadHeaderTimeout = 50 * time.Millisecond
	l, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer s.Close()
	go s.Serve(l)
	c, e := net.Dial("tcp", l.Addr().String())
	if e != nil {
		t.Fatal(e)
	}
	defer c.Close()
	io.WriteString(c, "GET / HTTP/1.1\r\nHost: test\r\n")
	time.Sleep(150 * time.Millisecond)
	c.SetReadDeadline(time.Now().Add(time.Second))
	line, e := bufio.NewReader(c).ReadString('\n')
	if ne, ok := e.(net.Error); ok && ne.Timeout() {
		t.Fatal("incomplete headers left connection open")
	}
	if e == nil && !strings.Contains(line, "408") {
		t.Fatalf("unexpected response %q", line)
	}
}
