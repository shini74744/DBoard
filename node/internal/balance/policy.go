// Package balance implements the same scheduling contract for both proxy cores.
// It does not open listeners or change the selected core.
package balance

import (
	"fmt"
	"net/url"
	"strings"
	"time"
)

const DefaultProbeURL = "https://www.gstatic.com/generate_204"

// Config is internal to Xboard-Node. Legacy panel field names are converted by
// adapters; the panel's stored selector/fallback_tag data need not be migrated.
type Config struct {
	Strategy         string        `json:"strategy"`
	Members          []string      `json:"members"`
	Fallback         string        `json:"fallback,omitempty"`
	ProbeURL         string        `json:"probe_url,omitempty"`
	ProbeInterval    time.Duration `json:"-"`
	ProbeTimeout     time.Duration `json:"-"`
	FailureThreshold int           `json:"-"`
}

func NormalizeStrategy(s string) (string, error) {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "", "random":
		return "random", nil
	case "roundrobin", "round_robin":
		return "round_robin", nil
	case "leastping", "latency":
		return "latency", nil
	case "leastload", "least_load":
		return "least_load", nil
	default:
		return "", fmt.Errorf("unsupported balancing strategy %q", s)
	}
}

func (c Config) Normalize() (Config, error) {
	strategy, err := NormalizeStrategy(c.Strategy)
	if err != nil {
		return c, err
	}
	c.Strategy = strategy
	seen := make(map[string]bool)
	members := make([]string, 0, len(c.Members))
	for _, s := range c.Members {
		s = strings.TrimSpace(s)
		if s == "" {
			return c, fmt.Errorf("empty balancer member")
		}
		if seen[s] {
			return c, fmt.Errorf("duplicate balancer member %q", s)
		}
		seen[s] = true
		members = append(members, s)
	}
	if len(members) == 0 || len(members) > 256 {
		return c, fmt.Errorf("balancer requires 1..256 distinct members")
	}
	c.Members = members // Never alias caller-owned slices.
	c.Fallback = strings.TrimSpace(c.Fallback)
	if c.ProbeURL == "" {
		c.ProbeURL = DefaultProbeURL
	}
	u, err := url.Parse(c.ProbeURL)
	if err != nil || u.Hostname() == "" || (u.Scheme != "http" && u.Scheme != "https") || u.User != nil {
		return c, fmt.Errorf("invalid balancer probe_url")
	}
	if c.ProbeInterval == 0 {
		c.ProbeInterval = 30 * time.Second
	}
	if c.ProbeTimeout == 0 {
		c.ProbeTimeout = 5 * time.Second
	}
	if c.ProbeInterval < 0 || c.ProbeTimeout < 0 {
		return c, fmt.Errorf("invalid probe timing")
	}
	if c.FailureThreshold == 0 {
		c.FailureThreshold = 2
	}
	if c.FailureThreshold < 1 {
		return c, fmt.Errorf("invalid probe failure threshold")
	}
	return c, nil
}
