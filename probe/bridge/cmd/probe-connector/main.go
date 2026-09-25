package main

import (
	"context"
	"encoding/json"
	"flag"
	"github.com/shini74744/DBoard/probe/bridge"
	"log"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	path := flag.String("config", "/etc/probe-connector.json", "connector configuration")
	flag.Parse()
	b, e := os.ReadFile(*path)
	if e != nil {
		log.Fatal(e)
	}
	var cfg bridge.Connector
	if e = json.Unmarshal(b, &cfg); e != nil {
		log.Fatal(e)
	}
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	if e = cfg.Run(ctx); e != nil {
		log.Fatal(e)
	}
}
