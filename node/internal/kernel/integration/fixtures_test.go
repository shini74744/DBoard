package integration_test

import (
	"bufio"
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"strconv"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// mockExit speaks real SOCKS5 TCP CONNECT and UDP ASSOCIATE. The payload reply
// identifies which exit carried a session. It never contacts the internet.
type mockExit struct {
	name     string
	listener net.Listener
	down     atomic.Bool
	delay    atomic.Int64
	probes   atomic.Int64
	mu       sync.Mutex
	closers  []io.Closer
}

func newExit(t *testing.T, name string) *mockExit {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	p := &mockExit{name: name, listener: l}
	p.track(l)
	t.Cleanup(p.close)
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			p.track(c)
			go p.handle(c)
		}
	}()
	return p
}
func (p *mockExit) track(c io.Closer) { p.mu.Lock(); p.closers = append(p.closers, c); p.mu.Unlock() }
func (p *mockExit) close() {
	p.mu.Lock()
	cs := append([]io.Closer(nil), p.closers...)
	p.mu.Unlock()
	for _, c := range cs {
		c.Close()
	}
}
func (p *mockExit) port() int {
	_, s, _ := net.SplitHostPort(p.listener.Addr().String())
	n, _ := strconv.Atoi(s)
	return n
}
func readAddress(r io.Reader) ([]byte, error) {
	typ := make([]byte, 1)
	if _, err := io.ReadFull(r, typ); err != nil {
		return nil, err
	}
	n := 0
	switch typ[0] {
	case 1:
		n = 4
	case 4:
		n = 16
	case 3:
		var length [1]byte
		if _, err := io.ReadFull(r, length[:]); err != nil {
			return nil, err
		}
		typ = append(typ, length[0])
		n = int(length[0])
	default:
		return nil, fmt.Errorf("unknown address type %d", typ[0])
	}
	rest := make([]byte, n+2)
	_, err := io.ReadFull(r, rest)
	return append(typ, rest...), err
}
func (p *mockExit) handle(c net.Conn) {
	defer c.Close()
	c.SetDeadline(time.Now().Add(20 * time.Second))
	head := make([]byte, 2)
	if _, err := io.ReadFull(c, head); err != nil {
		return
	}
	methods := make([]byte, int(head[1]))
	if _, err := io.ReadFull(c, methods); err != nil {
		return
	}
	c.Write([]byte{5, 0})
	req := make([]byte, 3)
	if _, err := io.ReadFull(c, req); err != nil {
		return
	}
	if _, err := readAddress(c); err != nil {
		return
	}
	if req[1] == 3 {
		pc, err := net.ListenPacket("udp", "127.0.0.1:0")
		if err != nil {
			return
		}
		defer pc.Close()
		p.track(pc)
		port := pc.LocalAddr().(*net.UDPAddr).Port
		c.Write([]byte{5, 0, 0, 1, 127, 0, 0, 1, byte(port >> 8), byte(port)})
		go func() {
			buf := make([]byte, 65535)
			for {
				n, addr, err := pc.ReadFrom(buf)
				if err != nil {
					return
				}
				if n < 4 || p.down.Load() {
					continue
				}
				packet := append([]byte(nil), buf[:n]...)
				reader := bytesReader(packet[3:])
				header, err := readAddress(reader)
				if err != nil {
					continue
				}
				reply := append(append([]byte{0, 0, 0}, header...), []byte(p.name+"\n")...)
				pc.WriteTo(reply, addr)
			}
		}()
		c.SetDeadline(time.Time{})
		io.Copy(io.Discard, c)
		return
	}
	if req[1] != 1 {
		return
	}
	c.Write([]byte{5, 0, 0, 1, 127, 0, 0, 1, 0, 0})
	r := bufio.NewReader(c)
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			return
		}
		if len(line) >= 4 && line[:4] == "GET " {
			for {
				line, err = r.ReadString('\n')
				if err != nil {
					return
				}
				if line == "\r\n" {
					break
				}
			}
			p.probes.Add(1)
			time.Sleep(time.Duration(p.delay.Load()))
			if p.down.Load() {
				io.WriteString(c, "HTTP/1.1 503 Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n")
			} else {
				io.WriteString(c, "HTTP/1.1 204 No Content\r\nX-Test-Exit: "+p.name+"\r\nConnection: close\r\n\r\n")
			}
			return
		}
		if p.down.Load() || line == "QUIT\n" {
			return
		}
		io.WriteString(c, p.name+"\n")
	}
}

// Tiny reader avoids sharing packet buffers with the next socket read.
type sliceReader struct{ b []byte }

func bytesReader(b []byte) *sliceReader { return &sliceReader{b: b} }
func (r *sliceReader) Read(b []byte) (int, error) {
	if len(r.b) == 0 {
		return 0, io.EOF
	}
	n := copy(b, r.b)
	r.b = r.b[n:]
	return n, nil
}

func freePort(t *testing.T) int {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := l.Addr().(*net.TCPAddr).Port
	l.Close()
	return port
}

const userID = "11111111-1111-1111-1111-111111111111"

func authControl(addr string, cmd byte, target string) (net.Conn, []byte, error) {
	c, err := net.DialTimeout("tcp", addr, time.Second)
	if err != nil {
		return nil, nil, err
	}
	ok := false
	defer func() {
		if !ok {
			c.Close()
		}
	}()
	c.SetDeadline(time.Now().Add(3 * time.Second))
	if _, err = c.Write([]byte{5, 1, 2}); err != nil {
		return nil, nil, err
	}
	var response [2]byte
	if _, err = io.ReadFull(c, response[:]); err != nil {
		return nil, nil, err
	}
	if response[1] != 2 {
		return nil, nil, fmt.Errorf("auth method %v", response)
	}
	auth := append([]byte{1, byte(len(userID))}, []byte(userID)...)
	auth = append(auth, byte(len(userID)))
	auth = append(auth, []byte(userID)...)
	c.Write(auth)
	if _, err = io.ReadFull(c, response[:]); err != nil {
		return nil, nil, err
	}
	if response[1] != 0 {
		return nil, nil, fmt.Errorf("auth failed")
	}
	host, ps, err := net.SplitHostPort(target)
	if err != nil {
		return nil, nil, err
	}
	port, _ := strconv.Atoi(ps)
	request := []byte{5, cmd, 0}
	ip := net.ParseIP(host)
	if ip4 := ip.To4(); ip4 != nil {
		request = append(request, 1)
		request = append(request, ip4...)
	} else if ip != nil {
		request = append(request, 4)
		request = append(request, ip.To16()...)
	} else {
		request = append(request, 3, byte(len(host)))
		request = append(request, []byte(host)...)
	}
	request = append(request, byte(port>>8), byte(port))
	c.Write(request)
	var head [3]byte
	if _, err = io.ReadFull(c, head[:]); err != nil {
		return nil, nil, err
	}
	if head[1] != 0 {
		return nil, nil, fmt.Errorf("SOCKS response %v", head)
	}
	bound, err := readAddress(c)
	if err != nil {
		return nil, nil, err
	}
	ok = true
	return c, bound, nil
}
func tcpSession(t *testing.T, addr string) (net.Conn, string) {
	t.Helper()
	c, _, err := authControl(addr, 1, "198.18.0.1:443")
	if err != nil {
		t.Fatal(err)
	}
	io.WriteString(c, "PING\n")
	r := bufio.NewReader(c)
	reply, err := r.ReadString('\n')
	if err != nil {
		c.Close()
		t.Fatal(err)
	}
	return c, reply
}
func boundUDP(b []byte) (string, error) {
	var host string
	switch b[0] {
	case 1:
		host = net.IP(b[1:5]).String()
	case 4:
		host = net.IP(b[1:17]).String()
	default:
		return "", fmt.Errorf("invalid UDP bind")
	}
	port := binary.BigEndian.Uint16(b[len(b)-2:])
	return net.JoinHostPort(host, strconv.Itoa(int(port))), nil
}
