package model

import (
	"crypto/tls"
	"crypto/x509"
	"fmt"
	"github.com/shini74744/DBoard/node/internal/config"
	"reflect"
	"strings"
)

func (n *NodeSpec) InboundTag() string {
	if n.FrontGate != nil {
		return n.FrontGate.OriginalProtocol + "-in"
	}
	return n.Protocol + "-in"
}
func (n *NodeSpec) WireProtocol() string {
	if n.FrontGate != nil {
		return "dboard-front-only"
	}
	return n.Protocol
}
func (n *NodeSpec) normalizeFrontGate() {
	if n.FrontGate == nil {
		return
	}
	g := n.FrontGate
	n.Protocol = "vless"
	n.Network = "tcp"
	n.NetworkSettings = nil
	n.TLS = 1
	n.Flow = ""
	n.Decryption = "none"
	n.TLSSettings = nil
	n.AcceptProxyProtocol = false
	n.Multiplex = nil
	n.AutoTLS = false
	n.CertConfig = &config.CertConfig{CertMode: "content", CertContent: g.Certificate, KeyContent: g.PrivateKey}
}
func (n *NodeSpec) validateFrontGate() error {
	if n.FrontGate == nil {
		if n.Protocol == "dboard-front-only" {
			return fmt.Errorf("front identity missing")
		}
		return nil
	}
	g := n.FrontGate
	if g.Version != 1 || g.OriginalProtocol == "" || strings.ContainsAny(g.OriginalProtocol, " /\\") {
		return fmt.Errorf("invalid front gate identity version or original protocol")
	}
	if n.Protocol != "vless" || n.TLS != 1 || n.Network != "tcp" || n.Flow != "" || n.GetProxyProtocol() {
		return fmt.Errorf("front gate transport must use authenticated raw TLS")
	}
	if _, err := tls.X509KeyPair([]byte(g.Certificate), []byte(g.PrivateKey)); err != nil {
		return fmt.Errorf("invalid front gate server certificate")
	}
	for _, pem := range g.TrustedClients {
		if !x509.NewCertPool().AppendCertsFromPEM([]byte(pem)) {
			return fmt.Errorf("invalid allowed front certificate")
		}
	}
	return nil
}

// FrontGateChanged requires synchronous retirement of the previous listener and sessions.
func FrontGateChanged(a, b *NodeSpec) bool {
	if a == nil {
		return b != nil && b.FrontGate != nil
	}
	if b == nil {
		return a.FrontGate != nil
	}
	return !reflect.DeepEqual(a.FrontGate, b.FrontGate)
}
