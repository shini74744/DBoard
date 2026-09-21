#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-dboard:local}"

cd "$ROOT"
echo "Building DBoard image: $TAG"

docker build   --label "org.opencontainers.image.revision=$(git rev-parse HEAD 2>/dev/null || echo unknown)"   -t "$TAG"   panel

docker image inspect "$TAG"   --format 'image={{.Id}} size={{.Size}}'
