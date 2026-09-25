#!/usr/bin/env bash
set -euo pipefail
version="$1"
[[ "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Usage: scripts/release-probe.sh vX.Y.Z'; exit 2; }
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
bash probe/build.sh "$version"
(cd node; GOMAXPROCS=2 make VERSION="$version" build-all)
out="$root/probe/build/$version"
for arch in amd64 arm64; do
 for name in DUI-node xboard-node; do cp "node/DBoard-node-linux-$arch" "node/$name-linux-$arch"; done
 for name in DBoard-node DUI-node xboard-node xbctl; do cp "node/$name-linux-$arch" "$out/"; done
done
(cd node; sha256sum DBoard-node-linux-* DUI-node-linux-* xboard-node-linux-* xbctl-linux-* > SHA256SUMS)
(cd "$out"; sha256sum *-linux-* probe-dashboard probe-connector install.sh > SHA256SUMS)
echo "Built release artifacts in $out. Commit source and binary updates before publishing."
echo "Create a prerelease for a canary, then promote only after health checks."
