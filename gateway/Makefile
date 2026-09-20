VERSION ?= dev
BUILD_TIME := $(shell date -u +"%Y-%m-%dT%H:%M:%SZ")
COMMIT := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
LDFLAGS := -s -w -X main.version=$(VERSION) -X main.buildTime=$(BUILD_TIME) -X main.commit=$(COMMIT)

.PHONY: build test build-all clean

build:
	go build -ldflags "$(LDFLAGS)" -o DUI-Gateway ./cmd/DUI-Gateway

test:
	go test ./...

build-all:
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags "$(LDFLAGS)" -o DUI-Gateway-linux-amd64 ./cmd/DUI-Gateway
	CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -ldflags "$(LDFLAGS)" -o DUI-Gateway-linux-arm64 ./cmd/DUI-Gateway

clean:
	rm -f DUI-Gateway DUI-Gateway-linux-amd64 DUI-Gateway-linux-arm64 SHA256SUMS
