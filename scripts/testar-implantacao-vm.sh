#!/usr/bin/env bash
# Executado DENTRO do contêiner que simula a VM .8 (como root, igual ao operador com sudo). Ver testar-implantacao.sh.
set -uo pipefail
ok=0; falhou=0
caso() { local nome="$1" esperado="$2"; shift 2; local s rc; s=$("$@" 2>&1); rc=$?
  if { [ "$esperado" = sucesso ] && [ $rc -eq 0 ]; } || { [ "$esperado" = falha ] && [ $rc -ne 0 ]; }; then echo "  ✓ $nome"; ok=$((ok+1))
  else echo "  ✗ $nome (rc=$rc)"; echo "$s" | tail -8 | sed 's/^/      /'; falhou=$((falhou+1)); fi; ULT="$s"; }
export BASE=/srv/sigarb PHP_BIN=php USUARIO_APP=sigarb DB_USUARIO_APP=rel_app DISCO_MINIMO_MB=512 TENTATIVAS_SAUDE=8
export URL_SAUDE=http://127.0.0.1:8099/api/v1/health
# "Reload do FPM": servidor embutido reiniciado no current (resolve o symlink a cada recarga, como o FPM com opcache resetado).
export RECARREGAR_FPM='pkill -f "php -S 127.0.0.1:8099" || true; sleep 0.3; (cd /srv/sigarb/current/backend/public && setsid -f su -s /bin/sh sigarb -c "php -S 127.0.0.1:8099 index.php >/dev/null 2>&1")'
command -v flock >/dev/null || { echo "flock ausente"; exit 1; }

echo "== Preparação única (equivale ao passo 1 do RUNBOOK)"
groupadd -r sigarb; useradd -r -g sigarb -s /usr/sbin/nologin sigarb
install -d -m 0755 $BASE $BASE/releases; install -d -g sigarb -m 0750 $BASE/shared
install -d -o sigarb -g sigarb -m 0750 $BASE/shared/storage $BASE/shared/storage/framework $BASE/shared/storage/framework/{cache,cache/data,sessions,views} $BASE/shared/storage/logs
install -d -o sigarb -g sigarb -m 0700 $BASE/shared/storage/app $BASE/shared/storage/app/private
K="base64:$(head -c 32 /dev/urandom | base64)"
( umask 027; cat > $BASE/shared/.env <<EOF
APP_NAME=SIGARB
APP_ENV=production
APP_DEBUG=false
APP_KEY=$K
APP_URL=http://127.0.0.1:8099
LOG_CHANNEL=sigarb_json
DB_CONNECTION=mysql
DB_HOST=sigarb-dev-mysql
DB_PORT=3306
DB_DATABASE=sigarb_rel
DB_USERNAME=rel_app
DB_PASSWORD=$SA
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
SIGARB_SAUDE_CACHE=file
SIGARB_DOCUMENTOS_ROOT=$BASE/shared/storage/app/private
SIGARB_DISCO_MINIMO_BYTES=1048576
EOF
); chgrp sigarb $BASE/shared/.env
( umask 077; printf 'DB_USERNAME=rel_migr\nDB_PASSWORD=%s\n' "$SM" > $BASE/shared/migr.env )
N1=$(basename "$R1" .tar.gz); N2=$(basename "$R2" .tar.gz); OPS=/tmp/ops; mkdir -p $OPS; tar -xzf "$R1" -C /tmp; cp -a /tmp/$N1/ops/. $OPS/

echo "== Preflight e simulação"
caso "preflight do pacote R1 (pendências institucionais apenas listadas)" sucesso $OPS/preflight.sh "$R1"
caso "simulação não cria current nem release" sucesso bash -c "$OPS/implantar.sh $R1 --simular | grep -q SIMULACAO_OK && [ ! -e $BASE/current ] && [ ! -d $BASE/releases/$N1 ]"
caso "pacote com checksum externo divergente é recusado" falha $OPS/implantar.sh /pacotes/sigarb-adulterada.tar.gz
caso "instalação com migrações pendentes exige --migrar" falha $OPS/implantar.sh "$R1"
caso "--migrar sem backup verificado é recusado" falha $OPS/implantar.sh "$R1" --migrar
caso "nada foi trocado após as recusas" sucesso test ! -e $BASE/current

echo "== Primeira implantação (R1, com migração)"
caso "implantação R1 com migração" sucesso env BACKUP_VERIFICADO=TESTE-LOCAL $OPS/implantar.sh "$R1" --migrar
caso "current = R1 e saúde 200" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N1 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
caso "config em cache sem leitura por outros (root:sigarb 0640)" sucesso bash -c "[ -z \"\$(find $BASE/releases/$N1/backend/bootstrap/cache -name '*.php' -perm /o=rwx)\" ] && stat -c '%a %G' $BASE/releases/$N1/backend/bootstrap/cache/config.php | grep -qx '640 sigarb'"
caso "release sem escrita para o pool" sucesso bash -c "! su -s /bin/sh sigarb -c 'touch $BASE/releases/$N1/backend/app/x' 2>/dev/null"
caso "cadeia de auditoria íntegra" sucesso bash -c "cd $BASE/current/backend && su -s /bin/sh sigarb -c 'php artisan sigarb:auditoria:verificar' | grep -q íntegra"
caso "operações bloqueadas continuam bloqueadas (só ENTRADA ESTORNO)" sucesso grep -qx 'operacoes_habilitadas=ENTRADA ESTORNO ' $BASE/current/MANIFESTO.txt
echo "documento-sintetico" > $BASE/shared/storage/app/private/upload-teste.bin; chown sigarb: $BASE/shared/storage/app/private/upload-teste.bin
H_ENV=$(sha256sum $BASE/shared/.env | cut -d' ' -f1)

echo "== Atualização (R2) e reversão"
rm -f $BASE/shared/migr.env
caso "implantação R2 sem migrações pendentes" sucesso $OPS/implantar.sh "$R2"
caso "current = R2" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N2 ]"
caso "reversão para R1" sucesso $OPS/reverter.sh
caso "current = R1 e saúde 200 após reversão" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N1 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
caso "upload preservado" sucesso grep -qx documento-sintetico $BASE/shared/storage/app/private/upload-teste.bin
caso ".env preservado" sucesso bash -c "[ \$(sha256sum $BASE/shared/.env | cut -d' ' -f1) = $H_ENV ]"

echo "== Injeção de falhas"
caso "release com saúde 500 é recusada" falha $OPS/implantar.sh /pacotes/sigarb-quebrada.tar.gz
caso "rollback automático manteve R1 ativo e saudável" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N1 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
# Banco à frente do código: registro de migração futura (inserido pela identidade de MIGRAÇÃO, como faria uma release mais nova).
( set -a; printf 'DB_USERNAME=rel_migr\nDB_PASSWORD=%s\n' "$SM" > /tmp/m.env; . /tmp/m.env; set +a; rm -f /tmp/m.env
  cd $BASE/current/backend && rm -f bootstrap/cache/config.php && php -r '
  require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  Illuminate\Support\Facades\DB::table("migrations")->insert(["migration"=>"2099_01_01_000000_futura","batch"=>99]);' )
caso "reversão bloqueada quando o banco tem migração desconhecida" falha $OPS/reverter.sh "$N2"
caso "implantação bloqueada quando o banco está à frente do código" falha $OPS/implantar.sh "$R2"
( set -a; printf 'DB_USERNAME=rel_migr\nDB_PASSWORD=%s\n' "$SM" > /tmp/m.env; . /tmp/m.env; set +a; rm -f /tmp/m.env
  cd $BASE/current/backend && php -r '
  require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  Illuminate\Support\Facades\DB::table("migrations")->where("migration","2099_01_01_000000_futura")->delete();' )
(cd $BASE/releases/$N1 && BASE=$BASE bash -c ". ops/comum.sh; cachear $BASE/releases/$N1") >/dev/null 2>&1

echo "== Implantação interrompida (SIGKILL durante a extração)"
rm -rf $BASE/releases/$N2
$OPS/implantar.sh "$R2" >/dev/null 2>&1 & P=$!
for _ in $(seq 1 100); do ls -d $BASE/releases/.extraindo-* >/dev/null 2>&1 && break; sleep 0.05; done
kill -9 $P 2>/dev/null; wait $P 2>/dev/null
caso "após a interrupção, current continua íntegro e saudável" sucesso bash -c "[ -d \$(readlink -f $BASE/current)/backend ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
caso "reexecução conclui a implantação de R2" sucesso $OPS/implantar.sh "$R2"
caso "nenhuma sobra de extração" sucesso bash -c "! ls -d $BASE/releases/.extraindo-* 2>/dev/null"
caso "segredos sem leitura por outros em toda a árvore" sucesso bash -c "[ -z \"\$(find $BASE -name '.env' -perm /o=r; find $BASE -path '*bootstrap/cache/config.php' -perm /o=r)\" ]"
echo "Histórico: $(tr '\n' ';' < $BASE/releases/HISTORICO)"
echo "Resultado: $ok ok, $falhou falha(s)"
[ $falhou -eq 0 ]
