package model

import (
	"github.com/shini74744/DBoard/node/internal/balance"
	"time"
)

func (b CustomBalancer) BalanceConfig() balance.Config {
	return balance.Config{Strategy: b.Strategy, Members: b.Selector, Fallback: b.FallbackTag, ProbeURL: b.ProbeURL,
		ProbeInterval: time.Duration(b.ProbeIntervalSeconds) * time.Second, ProbeTimeout: time.Duration(b.ProbeTimeoutSeconds) * time.Second}
}
