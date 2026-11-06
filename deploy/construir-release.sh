#!/usr/bin/env bash
# SIGARB — constrói o pacote de release REPRODUTÍVEL de um SHA exato. Não implanta nada.
# Uso: deploy/construir-release.sh [SHA=HEAD] [destino=.release]
# - Fonte: `git archive` do SHA (apenas arquivos versionados; nada do working tree, nenhum .env, nenhum upload).
# - Dependências: composer.lock e package-lock.json (fixadas); imagens de build fixadas por digest.
# - Tar determinístico (ordem, mtime do commit, dono 0:0, gzip -n): mesmo SHA → mesmo SHA-256.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SHA=$(git -C "$RAIZ" rev-parse --verify "${1:-HEAD}^{commit}")
DEST="$(mkdir -p "${2:-$RAIZ/.release}" && cd "${2:-$RAIZ/.release}" && pwd)"
CURTO=${SHA:0:12}; NOME="sigarb-$CURTO"
IMG_COMPOSER="composer@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c" # composer:2
IMG_NODE="node@sha256:18a6d90fd60542269850c80ca03b29b1d9b14d6b221ac7ee8e35b0d33ef904bd"     # node:22
EPOCA=$(git -C "$RAIZ" show -s --format=%ct "$SHA")
TMP=$(mktemp -d); trap 'chmod -R u+w "$TMP" 2>/dev/null; rm -rf "$TMP"' EXIT
U="$(id -u):$(id -g)"

echo "== 1. Fonte exata de $SHA"
mkdir -p "$TMP/src"; git -C "$RAIZ" archive "$SHA" | tar -x -C "$TMP/src"

echo "== 2. Dependências PHP (composer.lock, --no-dev)"
docker run --rm --network host --user "$U" -v "$TMP/src/backend:/app" -w /app -e COMPOSER_HOME=/tmp/c \
  -e COMPOSER_ALLOW_SUPERUSER=0 --entrypoint sh "$IMG_COMPOSER" -c \
  'composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress --ignore-platform-req=ext-pcntl \
   && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts' >/dev/null
# Conferência: as versões instaladas são exatamente as do lock.
docker run --rm --user "$U" -v "$TMP/src/backend:/app:ro" -w /app -e COMPOSER_HOME=/tmp/c "$IMG_COMPOSER" \
  composer validate --no-check-publish --no-check-all --strict --quiet

echo "== 3. Frontend (package-lock.json, npm ci, build)"
docker run --rm --network host --user "$U" -v "$TMP/src/frontend:/app" -w /app -e HOME=/tmp -e npm_config_cache=/tmp/npm \
  -e SOURCE_DATE_EPOCH="$EPOCA" "$IMG_NODE" sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run build >/dev/null'

echo "== 4. Montagem (somente runtime)"
R="$TMP/$NOME"; mkdir -p "$R"
cp -a "$TMP/src/backend" "$R/backend"; cp -a "$TMP/src/frontend/dist" "$R/frontend"
cp -a "$TMP/src/deploy/ops" "$R/ops"; cp -a "$TMP/src/deploy/modelos" "$R/modelos"
cp "$TMP/src/deploy/RUNBOOK.md" "$R/RUNBOOK.md"
# Exclusões: testes, configuração de dev, segredos de exemplo e artefatos de desenvolvimento.
rm -rf "$R/backend/tests" "$R/backend/phpunit.xml" "$R/backend/.env.example" "$R/backend/.editorconfig" \
       "$R/backend/storage/app/private/"* "$R/backend/storage/logs/"*.log "$R/backend/node_modules" \
       "$R/backend/server.php" "$R/backend/AGENTS.md" "$R/backend/CLAUDE.md" "$R/backend/README.md" "$R/backend/.npmrc" "$R/backend/.gitignore" "$R/backend/package.json" "$R/backend/vite.config.js" "$R/backend/resources/js" "$R/backend/resources/css"
find "$R" -name '.gitkeep' -o -name '.gitattributes' | xargs -r rm -f
chmod 0755 "$R"/ops/*.sh "$R/backend/artisan"   # explícito: o disco de origem pode não preservar o bit (FUSE/NTFS)
# Varredura negativa: nada disso pode estar no pacote.
if find "$R" \( -name '.env' -o -name '*.env' -o -name '*.docx' -o -name '*.key' -o -name '*.chave' -o -name '*.gpg' -o -name '*.sql.gz' -o -name 'dump*.sql' \) | grep -q .; then
  echo "ERRO: arquivo proibido no pacote"; exit 1; fi
grep -rIl --exclude-dir=vendor -E 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}|SIGARB_DEMO_PASSWORD=.+' "$R" && { echo "ERRO: segredo no pacote"; exit 1; } || true

echo "== 5. Manifesto"
MIG=$(ls "$R/backend/database/migrations" | sed 's/\.php$//' | sort)
{ echo "produto=SIGARB"; echo "git_sha=$SHA"; echo "epoca_commit=$EPOCA"
  echo "schema_ultima_migracao=$(echo "$MIG" | tail -1)"; echo "schema_total_migracoes=$(echo "$MIG" | wc -l)"
  echo "laravel=$(grep -A1 '"name": "laravel/framework"' "$TMP/src/backend/composer.lock" | sed -n 's/.*"version": "\(.*\)".*/\1/p')"
  echo "php_minimo=8.5"; echo "imagem_composer=$IMG_COMPOSER"; echo "imagem_node=$IMG_NODE"
  echo "composer_lock_sha256=$(sha256sum "$TMP/src/backend/composer.lock" | cut -d' ' -f1)"
  echo "package_lock_sha256=$(sha256sum "$TMP/src/frontend/package-lock.json" | cut -d' ' -f1)"
  echo "operacoes_habilitadas=$(grep -oE "'[A-Z]+' => \['estado' => 'HABILITADA'" "$R/backend/config/sigarb.php" | cut -d"'" -f2 | tr '\n' ' ')"
} > "$R/MANIFESTO.txt"
echo "$MIG" > "$R/MIGRACOES.txt"
(cd "$R" && find . -type f ! -name SHA256SUMS -print0 | LC_ALL=C sort -z | xargs -0 sha256sum) > "$R/SHA256SUMS"

echo "== 6. Pacote determinístico"
ARQ="$DEST/$NOME.tar.gz"
tar --sort=name --mtime="@$EPOCA" --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
    --pax-option=exthdr.name=%d/PaxHeaders/%f,delete=atime,delete=ctime -C "$TMP" -cf - "$NOME" | gzip -n -9 > "$ARQ"
(cd "$DEST" && sha256sum "$NOME.tar.gz" > "$NOME.tar.gz.sha256")
echo "RELEASE_OK $ARQ $(cut -d' ' -f1 "$ARQ.sha256") tamanho=$(du -h "$ARQ" | cut -f1)"
