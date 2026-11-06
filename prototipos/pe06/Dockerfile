# PROTÓTIPO PE-06 — NÃO é usado pela aplicação. Somente avaliação local.
FROM debian:stable-slim
RUN apt-get update && apt-get install -y --no-install-recommends qpdf ghostscript python3 \
 && rm -rf /var/lib/apt/lists/* && useradd -m proto
USER proto
WORKDIR /work
