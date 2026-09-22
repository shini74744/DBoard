#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 vX.Y.Z"
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/node"

command -v gh >/dev/null 2>&1 || { echo "gh CLI is required"; exit 1; }
gh auth status >/dev/null

VERSION="$VERSION" make build-all

for arch in amd64 arm64; do
  cp "DBoard-node-linux-${arch}" "DUI-node-linux-${arch}"
  cp "DBoard-node-linux-${arch}" "xboard-node-linux-${arch}"
done

sha256sum \
  DBoard-node-linux-amd64 DUI-node-linux-amd64 xboard-node-linux-amd64 xbctl-linux-amd64 \
  DBoard-node-linux-arm64 DUI-node-linux-arm64 xboard-node-linux-arm64 xbctl-linux-arm64 > SHA256SUMS
gh release create "$VERSION" \
  --repo shini74744/DBoard \
  --target main \
  --title "DBoard $VERSION" \
  --generate-notes \
  --latest \
  DBoard-node-linux-amd64 DUI-node-linux-amd64 xboard-node-linux-amd64 xbctl-linux-amd64 \
  DBoard-node-linux-arm64 DUI-node-linux-arm64 xboard-node-linux-arm64 xbctl-linux-arm64 \
  SHA256SUMS
