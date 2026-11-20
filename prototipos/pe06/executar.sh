#!/usr/bin/env bash
# PE-06 — avaliação isolada, sem Docker (decisão do responsável, 2026-11-19): diretório temporário descartável,
# sem rede (unshare, quando o kernel permite namespaces sem root), 512 MB de memória, fixtures sintéticas geradas na hora.
# Requer qpdf, ghostscript e python3 no host (antes vinham da imagem debian:stable-slim).
set -euo pipefail
AQUI="$(cd "$(dirname "$0")" && pwd)"
for b in qpdf gs python3; do command -v "$b" >/dev/null || { echo "ERRO: $b não encontrado (instale qpdf e ghostscript)"; exit 2; }; done
W=$(mktemp -d); trap 'rm -rf "$W"' EXIT
SEM_REDE=(); unshare -rn true 2>/dev/null && SEM_REDE=(unshare -rn)
cd "$W" && "${SEM_REDE[@]}" prlimit --as=$((512*1024*1024)) python3 -I "$AQUI/avaliar.py"
