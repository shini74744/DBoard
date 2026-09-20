#!/usr/bin/env bash
set -Eeuo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 gateway-vX.Y.Z"
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/gateway"

command -v gh >/dev/null 2>&1 || { echo "gh CLI is required"; exit 1; }
gh auth status >/dev/null

VERSION="$VERSION" make test
VERSION="$VERSION" make build-all

sha256sum \
  DUI-Gateway-linux-amd64 \
  DUI-Gateway-linux-arm64 > SHA256SUMS

gh release create "$VERSION" \
  --repo shini74744/DBoard \
  --target main \
  --title "DUI-Gateway $VERSION" \
  --generate-notes \
  --prerelease \
  DUI-Gateway-linux-amd64 \
  DUI-Gateway-linux-arm64 \
  SHA256SUMS
