#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
version="${1:-v0.2.0-dev}"
[[ "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$ ]]
out="$root/probe/build/$version"
mkdir -p "$out"
python3 "$root/probe/prepare-dashboard.py"
(cd "$root/probe/dashboard"; go run github.com/swaggo/swag/cmd/swag@v1.16.6 init -g cmd/dashboard/main.go -o cmd/dashboard/docs --parseDependency --parseInternal)
for arch in amd64 arm64; do
 (cd "$root/node"; CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -p 2 -trimpath -tags 'with_quic with_utls with_wireguard with_acme with_clash_api' -ldflags "-s -w -X main.version=$version" -o "$out/nezha-agent-linux-$arch" ./cmd/nezha-agent)
done
(cd "$out"; sha256sum nezha-agent-linux-* > SHA256SUMS)
(cd "$root/probe/bridge"; CGO_ENABLED=0 go build -p 2 -trimpath -ldflags '-s -w' -o "$out/probe-connector" ./cmd/probe-connector)
(cd "$root/probe/dashboard"; go build -p 2 -trimpath -ldflags '-s -w' -o "$out/probe-dashboard" ./cmd/dashboard)
cp "$root/probe/install-agent.sh" "$out/install.sh"
printf '{"version":"%s"}\n' "$version" > "$out/release.json"
