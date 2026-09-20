package kernel

import (
	"crypto/sha256"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"github.com/shini74744/DBoard/node/internal/config"
	"github.com/shini74744/DBoard/node/internal/kernel/geodata"
	"github.com/shini74744/DBoard/node/internal/model"
	XR "github.com/xtls/xray-core/app/router"
	"go4.org/netipx"
	"google.golang.org/protobuf/proto"
)

// Both backends expand structured GeoSite/GeoIP selectors from the SAME .dat
// snapshot. This avoids different upstream lists silently changing rule meaning.
// The cache is bounded to one immutable version of each dataset, not per node.
var universalGeoCache struct {
	sync.Mutex
	siteHash, ipHash [32]byte
	sites            map[string]*XR.GeoSite
	ips              map[string]*XR.GeoIP
}

const maxGeoFile = 128 << 20
const maxExpandedRules = 500000

func readGeoData(path string) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if st.Size() <= 0 || st.Size() > maxGeoFile {
		return nil, fmt.Errorf("invalid geodata size in %s", path)
	}
	data := make([]byte, st.Size())
	// ReadFile is deliberately bounded by the size checked above.
	n := 0
	for n < len(data) {
		k, err := f.Read(data[n:])
		n += k
		if err != nil {
			return nil, err
		}
	}
	return data, nil
}
func loadSites(dir string) (map[string]*XR.GeoSite, error) {
	data, err := readGeoData(filepath.Join(dir, "geosite.dat"))
	if err != nil {
		return nil, err
	}
	hash := sha256.Sum256(data)
	universalGeoCache.Lock()
	defer universalGeoCache.Unlock()
	if universalGeoCache.sites != nil && hash == universalGeoCache.siteHash {
		return universalGeoCache.sites, nil
	}
	var list XR.GeoSiteList
	if err := proto.Unmarshal(data, &list); err != nil {
		return nil, fmt.Errorf("invalid geosite.dat: %w", err)
	}
	out := map[string]*XR.GeoSite{}
	for _, v := range list.Entry {
		out[strings.ToUpper(v.CountryCode)] = v
	}
	if len(out) == 0 {
		return nil, fmt.Errorf("empty geosite.dat")
	}
	universalGeoCache.sites = out
	universalGeoCache.siteHash = hash
	return out, nil
}
func loadIPs(dir string) (map[string]*XR.GeoIP, error) {
	data, err := readGeoData(filepath.Join(dir, "geoip.dat"))
	if err != nil {
		return nil, err
	}
	hash := sha256.Sum256(data)
	universalGeoCache.Lock()
	defer universalGeoCache.Unlock()
	if universalGeoCache.ips != nil && hash == universalGeoCache.ipHash {
		return universalGeoCache.ips, nil
	}
	var list XR.GeoIPList
	if err := proto.Unmarshal(data, &list); err != nil {
		return nil, fmt.Errorf("invalid geoip.dat: %w", err)
	}
	out := map[string]*XR.GeoIP{}
	for _, v := range list.Entry {
		out[strings.ToUpper(v.CountryCode)] = v
	}
	if len(out) == 0 {
		return nil, fmt.Errorf("empty geoip.dat")
	}
	universalGeoCache.ips = out
	universalGeoCache.ipHash = hash
	return out, nil
}

func PrepareStructuredRoutes(nc *model.NodeSpec, kcfg config.KernelConfig) (*model.NodeSpec, error) {
	if nc == nil {
		return nil, fmt.Errorf("nil node config")
	}
	out := *nc
	// Clone only the rules we modify. Other fields remain read-only.
	out.CustomRouteRules = make([]model.CustomRouteRule, len(nc.CustomRouteRules))
	needSite, needIP := false, false
	for _, rule := range nc.CustomRouteRules {
		if rule.Disabled {
			continue
		}
		for _, v := range append(append([]string(nil), rule.Match.Domains...), rule.Match.DomainSuffixes...) {
			if strings.HasPrefix(v, "geosite:") {
				needSite = true
			}
		}
		for _, v := range append(append([]string(nil), rule.Match.IPCIDRs...), rule.Match.SourceCIDRs...) {
			if strings.HasPrefix(v, "geoip:") {
				needIP = true
			}
		}
	}
	dir := kcfg.GeoDataDir
	if dir == "" {
		dir = kcfg.ConfigDir
	}
	var sites map[string]*XR.GeoSite
	var ips map[string]*XR.GeoIP
	if needSite || needIP {
		if dir == "" {
			return nil, fmt.Errorf("geodata directory is required for GeoSite/GeoIP")
		}
		if err := geodata.Ensure(dir, needIP, needSite, "xray"); err != nil {
			return nil, err
		}
		var err error
		if needSite {
			sites, err = loadSites(dir)
			if err != nil {
				return nil, err
			}
		}
		if needIP {
			ips, err = loadIPs(dir)
			if err != nil {
				return nil, err
			}
		}
	}
	for i, rule := range nc.CustomRouteRules {
		out.CustomRouteRules[i] = rule
		if rule.Disabled {
			continue
		}
		domains, suffixes := []string{}, []string{}
		addDomain := func(v string, suffix bool) error {
			if !strings.HasPrefix(v, "geosite:") {
				if suffix {
					suffixes = append(suffixes, v)
				} else {
					domains = append(domains, v)
				}
				return nil
			}
			parts := strings.Split(strings.TrimPrefix(v, "geosite:"), "@")
			site := sites[strings.ToUpper(parts[0])]
			if site == nil {
				return fmt.Errorf("unknown GeoSite %q", parts[0])
			}
			count := 0
			for _, domain := range site.Domain {
				match := true
				for _, attribute := range parts[1:] {
					found := false
					for _, attr := range domain.Attribute {
						if strings.EqualFold(attr.Key, attribute) {
							found = true
							break
						}
					}
					if !found {
						match = false
						break
					}
				}
				if !match {
					continue
				}
				count++
				switch domain.Type {
				case XR.Domain_Full:
					domains = append(domains, "full:"+domain.Value)
				case XR.Domain_Domain:
					suffixes = append(suffixes, domain.Value)
				case XR.Domain_Plain:
					domains = append(domains, "keyword:"+domain.Value)
				case XR.Domain_Regex:
					domains = append(domains, "regexp:"+domain.Value)
				default:
					return fmt.Errorf("unknown GeoSite domain type")
				}
			}
			if count == 0 {
				return fmt.Errorf("GeoSite %q has no matching entries; refusing to widen rule", v)
			}
			return nil
		}
		for _, v := range rule.Match.Domains {
			if err := addDomain(v, false); err != nil {
				return nil, fmt.Errorf("rule %d: %w", i, err)
			}
		}
		for _, v := range rule.Match.DomainSuffixes {
			if err := addDomain(v, true); err != nil {
				return nil, fmt.Errorf("rule %d: %w", i, err)
			}
		}
		dest, err := expandGeoIPs(rule.Match.IPCIDRs, ips)
		if err != nil {
			return nil, fmt.Errorf("rule %d: %w", i, err)
		}
		src, err := expandGeoIPs(rule.Match.SourceCIDRs, ips)
		if err != nil {
			return nil, fmt.Errorf("rule %d: %w", i, err)
		}
		if len(domains)+len(suffixes)+len(dest)+len(src) > maxExpandedRules {
			return nil, fmt.Errorf("rule %d exceeds expanded rule limit", i)
		}
		out.CustomRouteRules[i].Match.Domains = uniqueStrings(domains)
		out.CustomRouteRules[i].Match.DomainSuffixes = uniqueStrings(suffixes)
		out.CustomRouteRules[i].Match.IPCIDRs = uniqueStrings(dest)
		out.CustomRouteRules[i].Match.SourceCIDRs = uniqueStrings(src)
	}
	return &out, nil
}

func expandGeoIPs(values []string, sets map[string]*XR.GeoIP) ([]string, error) {
	out := []string{}
	for _, v := range values {
		if !strings.HasPrefix(v, "geoip:") {
			out = append(out, v)
			continue
		}
		name := strings.TrimPrefix(v, "geoip:")
		inverse := strings.HasPrefix(name, "!")
		name = strings.TrimPrefix(name, "!")
		item := sets[strings.ToUpper(name)]
		if item == nil {
			return nil, fmt.Errorf("unknown GeoIP %q", name)
		}
		inverse = inverse != item.ReverseMatch
		prefixes := make([]netip.Prefix, 0, len(item.Cidr))
		for _, cidr := range item.Cidr {
			ip, ok := netip.AddrFromSlice(cidr.Ip)
			if !ok {
				return nil, fmt.Errorf("invalid GeoIP address")
			}
			ip = ip.Unmap()
			p := netip.PrefixFrom(ip, int(cidr.Prefix))
			if !p.IsValid() {
				return nil, fmt.Errorf("invalid GeoIP prefix")
			}
			prefixes = append(prefixes, p.Masked())
		}
		if len(prefixes) == 0 {
			return nil, fmt.Errorf("empty GeoIP %q; refusing to widen rule", name)
		}
		if inverse {
			var b netipx.IPSetBuilder
			b.AddPrefix(netip.MustParsePrefix("0.0.0.0/0"))
			b.AddPrefix(netip.MustParsePrefix("::/0"))
			for _, p := range prefixes {
				b.RemovePrefix(p)
			}
			set, err := b.IPSet()
			if err != nil {
				return nil, err
			}
			prefixes = set.Prefixes()
		}
		for _, p := range prefixes {
			out = append(out, p.String())
		}
	}
	return out, nil
}
func uniqueStrings(in []string) []string {
	seen := map[string]bool{}
	out := make([]string, 0, len(in))
	for _, s := range in {
		if !seen[s] {
			seen[s] = true
			out = append(out, s)
		}
	}
	return out
}
