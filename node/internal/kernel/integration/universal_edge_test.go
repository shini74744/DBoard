package integration_test

import (
	"bufio"
	"github.com/shini74744/DBoard/node/internal/kernel"
	"github.com/shini74744/DBoard/node/internal/model"
	R "github.com/xtls/xray-core/app/router"
	"google.golang.org/protobuf/proto"
	"io"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func writeGeoFixtures(t *testing.T, dir string) {
	t.Helper()
	site := &R.GeoSiteList{Entry: []*R.GeoSite{{CountryCode: "TEST", Domain: []*R.Domain{
		{Type: R.Domain_Full, Value: "exact.xboard.test"}, {Type: R.Domain_Domain, Value: "suffix.xboard.test"},
		{Type: R.Domain_Plain, Value: "needle"}, {Type: R.Domain_Regex, Value: "^api\\.[a-z]+\\.xboard\\.test$"},
	}}}}
	ips := &R.GeoIPList{Entry: []*R.GeoIP{{CountryCode: "TEST", Cidr: []*R.CIDR{{Ip: []byte{198, 18, 0, 0}, Prefix: 24}, {Ip: net.ParseIP("2001:db8::").To16(), Prefix: 32}}}}}
	for name, data := range map[string]proto.Message{"geosite.dat": site, "geoip.dat": ips} {
		b, err := proto.Marshal(data)
		if err != nil {
			t.Fatal(err)
		}
		if err = os.WriteFile(filepath.Join(dir, name), b, 0600); err != nil {
			t.Fatal(err)
		}
	}
}
func TestSharedGeoDataIPv6AndRollback(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			a, b := newExit(t, "match"), newExit(t, "other")
			n := nodeWithExits(t, a, b)
			dir := t.TempDir()
			writeGeoFixtures(t, dir)
			n.CustomRouteRules = []model.CustomRouteRule{{IPDomainRelation: "or", Match: model.RouteMatch{Domains: []string{"geosite:test"}, IPCIDRs: []string{"geoip:test"}}, Action: model.RouteAction{Type: "route", Target: "match"}}, {Action: model.RouteAction{Type: "route", Target: "other"}}}
			k, addr := launchSpec(t, core, n, dir)
			for _, target := range []string{"exact.xboard.test:443", "sub.suffix.xboard.test:443", "has-needle.xboard.test:443", "api.foo.xboard.test:443", "198.18.0.5:443", "[2001:db8::10]:443"} {
				got, err := replyFor(addr, target)
				if err != nil || got != "match\n" {
					t.Fatalf("%s => %q: %v", target, got, err)
				}
			}
			got, err := replyFor(addr, "198.19.0.1:443")
			if err != nil || got != "other\n" {
				t.Fatalf("nonmatch => %q: %v", got, err)
			}
			invalid := cloneSpec(n)
			invalid.CustomRouteRules[0].Match.Domains = []string{"geosite:missing"}
			if err = k.Reload(invalid, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err == nil {
				t.Fatal("unknown geosite accepted")
			}
			got, err = replyFor(addr, "exact.xboard.test:443")
			if err != nil || got != "match\n" {
				t.Fatalf("failed reload changed working route: %q %v", got, err)
			}
			if err = os.WriteFile(filepath.Join(dir, "geosite.dat"), []byte("bad protobuf"), 0600); err != nil {
				t.Fatal(err)
			}
			if err = k.Reload(n, []model.UserSpec{{ID: 1, UUID: userID}}, kernel.TLSCert{}); err == nil {
				t.Fatal("corrupt geodata accepted")
			}
			if !k.IsRunning() {
				t.Fatal("bad geodata stopped original core")
			}
		})
	}
}
func TestUniversalLatencyAndFailClosed(t *testing.T) {
	for _, core := range []string{"xray", "singbox"} {
		t.Run(core, func(t *testing.T) {
			slow, fast := newExit(t, "slow"), newExit(t, "fast")
			slow.delay.Store(int64(100 * time.Millisecond))
			_, _, addr := startCore(t, core, "leastPing", slow, fast)
			deadline := time.Now().Add(4 * time.Second)
			for (slow.probes.Load() < 1 || fast.probes.Load() < 1) && time.Now().Before(deadline) {
				time.Sleep(20 * time.Millisecond)
			}
			time.Sleep(200 * time.Millisecond)
			for i := 0; i < 4; i++ {
				c, x := tcpSession(t, addr)
				c.Close()
				if x != "fast\n" {
					t.Fatalf("latency selected %q", x)
				}
			}
			slow.down.Store(true)
			fast.down.Store(true)
			// Two dedicated probe failures should exclude both, without direct fallback.
			deadline = time.Now().Add(12 * time.Second)
			for (slow.probes.Load() < 3 || fast.probes.Load() < 3) && time.Now().Before(deadline) {
				time.Sleep(25 * time.Millisecond)
			}
			time.Sleep(250 * time.Millisecond)
			c, _, err := authControl(addr, 1, "198.18.0.1:443")
			if err == nil {
				defer c.Close()
				c.SetDeadline(time.Now().Add(time.Second))
				io.WriteString(c, "PING\n")
				if reply, err := bufio.NewReader(c).ReadString('\n'); err == nil {
					t.Fatalf("all-down route unexpectedly returned %q", reply)
				}
			}
		})
	}
}
