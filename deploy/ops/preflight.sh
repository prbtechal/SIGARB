#!/usr/bin/env bash
# SIGARB — verificação prévia, SOMENTE LEITURA. Nada é alterado. Uso: ops/preflight.sh [pacote.tar.gz]
# Sai 0 só se todos os itens BLOQUEANTES passarem. Itens institucionais não verificáveis aqui são listados como PENDENTE.
. "$(dirname "$0")/comum.sh"
PKG="${1:-}"; erros=0; ok() { echo "  OK    $*"; }; bloq() { echo "  BLOQ  $*"; erros=$((erros+1)); }; pend() { echo "  PEND  $*"; }
echo "== Preflight SIGARB (BASE=$BASE)"
if [ -n "$PKG" ]; then
  [ -f "$PKG.sha256" ] && (cd "$(dirname "$PKG")" && sha256sum -c --status "$(basename "$PKG").sha256") && ok "checksum do pacote" || bloq "checksum do pacote ausente ou divergente"
fi
v=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo 0)
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION,"8.5.0",">=")?0:1);' 2>/dev/null && ok "PHP $v" || bloq "PHP >= 8.5 (encontrado: $v)"
MODS=$("$PHP_BIN" -m 2>/dev/null || true)   # lista capturada uma vez: `php -m | grep -q` falha por SIGPIPE com pipefail
for e in pdo_mysql mbstring openssl fileinfo ctype tokenizer xml; do
  grep -qix "$e" <<< "$MODS" && ok "extensão $e" || bloq "extensão PHP ausente: $e"; done
"$PHP_BIN" -r 'exit(ini_get("opcache.enable")||ini_get("opcache.enable_cli")!==false?0:1);' 2>/dev/null && ok "OPcache disponível" || pend "OPcache: conferir no pool FPM"
livre=$(df -Pm "$BASE" 2>/dev/null | awk 'NR==2{print $4}'); [ "${livre:-0}" -ge "$DISCO_MINIMO_MB" ] && ok "disco livre ${livre} MB" || bloq "disco livre ${livre:-?} MB < ${DISCO_MINIMO_MB} MB"
[ -d "$REL" ] && [ -d "$SH" ] && ok "estrutura releases/ e shared/" || bloq "estrutura $BASE/{releases,shared} ausente"
if [ -f "$SH/.env" ]; then
  p=$(stat -c %a "$SH/.env"); [ "${p: -1}" = 0 ] && ok ".env sem leitura por outros ($p)" || bloq ".env legível por outros ($p)"
  grep -q '^APP_ENV=production' "$SH/.env" && ok "APP_ENV=production" || bloq "APP_ENV != production"
  grep -q '^APP_DEBUG=false' "$SH/.env" && ok "APP_DEBUG=false" || bloq "APP_DEBUG != false"
  grep -qE '^APP_KEY=base64:.{40,}' "$SH/.env" && ok "APP_KEY definido" || bloq "APP_KEY ausente"
  grep -q "^DB_USERNAME=${DB_USUARIO_APP:-sigarb_app}$" "$SH/.env" && ok "runtime usa ${DB_USUARIO_APP:-sigarb_app}" || bloq "runtime não usa a identidade ${DB_USUARIO_APP:-sigarb_app}"
  if grep -q '^SESSION_SECURE_COOKIE=true' "$SH/.env"; then ok "cookie Secure"
  elif [ "${SIGARB_HTTP_INTERNO:-0}" = 1 ]; then pend "SESSION_SECURE_COOKIE != true: HTTP na rede interna (SIGARB_HTTP_INTERNO=1, autorizado 2026-11-10); migrar para HTTPS"
  else bloq "SESSION_SECURE_COOKIE != true (exige HTTPS; HTTP interno so com SIGARB_HTTP_INTERNO=1)"; fi
else bloq "shared/.env ausente"; fi
[ ! -e "$SH/migr.env" ] || { p=$(stat -c %a "$SH/migr.env"); [ "$p" = 600 ] && ok "migr.env 600 (remover após a janela)" || bloq "migr.env com permissão $p (exigido 600)"; }
d="$SH/storage/app/private"; if [ -d "$d" ]; then p=$(stat -c %a "$d"); [ "${p: -1}" = 0 ] && ok "documentos privados sem acesso de outros ($p)" || bloq "documentos privados acessíveis por outros ($p)"; else bloq "shared/storage/app/private ausente"; fi
if command -v timedatectl >/dev/null; then
  [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] && ok "relógio sincronizado (NTP)" || bloq "relógio NÃO sincronizado (auditoria exige hora confiável)"
else pend "timedatectl indisponível: verificar NTP"; fi
pend "TLS/DNS institucional, firewall 3306 só de .8, destino de backup cifrado e restauração verificada — evidência da Infra"
echo "Resultado: $([ $erros -eq 0 ] && echo 'PREFLIGHT_OK' || echo "PREFLIGHT_BLOQUEADO ($erros item(ns))")"
exit $([ $erros -eq 0 ] && echo 0 || echo 1)
