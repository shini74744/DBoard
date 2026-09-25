package probeagent

import (
	"encoding/json"
	"fmt"
	"github.com/shini74744/DBoard/probe/bridge"
	"net/url"
	"os"
	"path/filepath"
)

type Config struct {
	Endpoint   string   `json:"endpoint"`
	Backups    []string `json:"backups,omitempty"`
	UUID       string   `json:"uuid"`
	Secret     string   `json:"secret"`
	DataDir    string   `json:"data_dir"`
	Kernel     string   `json:"kernel,omitempty"`
	AllowLocal bool     `json:"-"`
}

func Load(path string) (Config, error) {
	var c Config
	b, e := os.ReadFile(path)
	if e != nil {
		return c, e
	}
	if e = json.Unmarshal(b, &c); e != nil {
		return c, e
	}
	return c, c.Validate()
}
func (c *Config) Validate() error {
	if _, e := bridge.PublicURL(c.Endpoint, c.AllowLocal); e != nil {
		return e
	}
	if !bridge.UUIDPattern.MatchString(c.UUID) || len(c.Secret) < 32 {
		return fmt.Errorf("invalid probe identity")
	}
	if len(c.Backups) > 8 {
		return fmt.Errorf("at most eight backup endpoints")
	}
	for _, s := range c.Backups {
		if _, e := bridge.PublicURL(s, c.AllowLocal); e != nil {
			return e
		}
	}
	if c.DataDir == "" {
		c.DataDir = "/var/lib/nezha-integrated-agent"
	}
	if c.Kernel == "" {
		c.Kernel = "singbox"
	}
	if c.Kernel != "singbox" && c.Kernel != "xray" {
		return fmt.Errorf("invalid kernel")
	}
	return nil
}
func atomicWrite(path string, data []byte, mode os.FileMode) error {
	if e := os.MkdirAll(filepath.Dir(path), 0700); e != nil {
		return e
	}
	f, e := os.CreateTemp(filepath.Dir(path), ".probe-*")
	if e != nil {
		return e
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	if e = f.Chmod(mode); e == nil {
		_, e = f.Write(data)
	}
	if e == nil {
		e = f.Sync()
	}
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if e == nil {
		e = os.Rename(tmp, path)
	}
	if e == nil {
		d, er := os.Open(filepath.Dir(path))
		if er == nil {
			e = d.Sync()
			d.Close()
		}
	}
	return e
}
func (c Config) Save(path string) error {
	b, e := json.MarshalIndent(c, "", "  ")
	if e != nil {
		return e
	}
	return atomicWrite(path, b, 0600)
}
func (c Config) Server() string {
	u, _ := url.Parse(c.Endpoint)
	if u.Port() == "" {
		return u.Host + ":443"
	}
	return u.Host
}
