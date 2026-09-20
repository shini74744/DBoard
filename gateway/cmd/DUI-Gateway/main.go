package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/shini74744/DBoard/gateway/internal/config"
	duigateway "github.com/shini74744/DBoard/gateway/internal/gateway"
)

var (
	version   = "dev"
	buildTime = "unknown"
	commit    = "unknown"
)

func main() {
	envFile := flag.String("env", "/etc/DUI-Gateway/gateway.env", "environment configuration file")
	showVersion := flag.Bool("v", false, "show version")
	flag.BoolVar(showVersion, "version", false, "show version")
	flag.Parse()

	if *showVersion {
		fmt.Printf("DUI-Gateway %s (built %s, commit %s)\n", version, buildTime, commit)
		return
	}

	cfg, err := config.Load(*envFile)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	handler := duigateway.New(cfg, version)
	srv := &http.Server{
		Addr:              duigateway.ListenAddr(cfg.Port),
		Handler:           handler,
		ReadHeaderTimeout: 10 * time.Second,
		IdleTimeout:       90 * time.Second,
		MaxHeaderBytes:    1 << 20,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	go func() {
		log.Printf("DUI-Gateway %s listening on %s, backend=%s", version, srv.Addr, cfg.BackendAPIURL.Redacted())
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("listen: %v", err)
		}
	}()

	<-ctx.Done()
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		log.Printf("shutdown: %v", err)
	}
}
