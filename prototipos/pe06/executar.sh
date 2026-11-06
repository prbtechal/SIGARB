#!/usr/bin/env bash
# PE-06 — avaliação isolada: sem rede, FS somente-leitura, 512 MB, fixtures sintéticas geradas na hora.
set -euo pipefail
cd "$(dirname "$0")"
docker build -q -t sigarb-proto-pe06:local . >/dev/null
docker run --rm --network none --read-only --tmpfs /work:rw,uid=1000 --tmpfs /tmp:rw,uid=1000 --memory 512m \
  -v "$PWD/avaliar.py:/opt/avaliar.py:ro" sigarb-proto-pe06:local sh -c 'cd /work && python3 /opt/avaliar.py'
