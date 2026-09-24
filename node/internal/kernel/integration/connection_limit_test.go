package integration_test

import (
	"fmt"
	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel"
	SB "github.com/shini74744/DBoard/node/internal/kernel/singbox"
	XR "github.com/shini74744/DBoard/node/internal/kernel/xray"
	"github.com/shini74744/DBoard/node/internal/model"
	"net"
	"testing"
	"time"
)

func TestConnectionLimitRealTCPAndLiveUpdate(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			cfg := config.KernelConfig{Type: core, LogLevel: "error", ConfigDir: t.TempDir()}
			var k kernel.Kernel
			if core == "xray" {
				k = XR.New(cfg)
			} else {
				k = SB.New(cfg)
			}
			port := freePort(t)
			n := &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: port, Network: "tcp", CustomRouteRules: []model.CustomRouteRule{{Action: model.RouteAction{Type: "direct"}}}}
			users := []model.UserSpec{{ID: 1, UUID: userID, ConnectionLimit: 1}}
			if err := k.Start(n, users, kernel.TLSCert{}); err != nil {
				t.Fatal(err)
			}
			defer k.Stop()
			destination := echoDestination(t)
			addr := fmt.Sprintf("127.0.0.1:%d", port)
			dial := func() (net.Conn, error) {
				c, _, err := authControl(addr, 1, destination)
				if err != nil {
					return nil, err
				}
				c.SetDeadline(time.Now().Add(600 * time.Millisecond))
				if _, err = c.Write([]byte("hello")); err == nil {
					b := make([]byte, 5)
					_, err = c.Read(b)
				}
				if err != nil {
					c.Close()
					return nil, err
				}
				return c, nil
			}
			first, err := dial()
			if err != nil {
				t.Fatal(err)
			}
			defer first.Close()
			if c, err := dial(); err == nil {
				c.Close()
				t.Fatal("second connection admitted above limit")
			}
			users[0].ConnectionLimit = 2
			if _, _, err := k.UpdateUsers(users); err != nil {
				t.Fatal(err)
			}
			second, err := dial()
			if err != nil {
				t.Fatalf("live increase: %v", err)
			}
			first.Close()
			second.Close()
			users[0].ConnectionLimit = 1
			if _, _, err := k.UpdateUsers(users); err != nil {
				t.Fatal(err)
			}
			deadline := time.Now().Add(3 * time.Second)
			for {
				c, err := dial()
				if err == nil {
					c.Close()
					break
				}
				if time.Now().After(deadline) {
					t.Fatal("slot not released after close", err)
				}
				time.Sleep(20 * time.Millisecond)
			}
		})
	}
}
