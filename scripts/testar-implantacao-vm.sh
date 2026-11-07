#!/usr/bin/env bash
# Executado DENTRO do contêiner que simula a VM .8 (como root, igual ao operador com sudo). Ver testar-implantacao.sh.
set -uo pipefail
ok=0; falhou=0
caso() { local nome="$1" esperado="$2"; shift 2; local s rc; s=$("$@" 2>&1); rc=$?
  if { [ "$esperado" = sucesso ] && [ $rc -eq 0 ]; } || { [ "$esperado" = falha ] && [ $rc -ne 0 ]; }; then echo "  ✓ $nome"; ok=$((ok+1))
  else echo "  ✗ $nome (rc=$rc)"; echo "$s" | tail -8 | sed 's/^/      /'; falhou=$((falhou+1)); fi; ULT="$s"; }
# Falha esperada E pelo motivo certo: a saída precisa casar com a expressão (evita falso positivo por erro alheio).
falha_por() { local nome="$1" motivo="$2"; shift 2; local s rc; s=$("$@" 2>&1); rc=$?
  if [ $rc -ne 0 ] && echo "$s" | grep -qE "$motivo"; then echo "  ✓ $nome"; ok=$((ok+1))
  else echo "  ✗ $nome (rc=$rc; motivo esperado: $motivo)"; echo "$s" | grep -E "BLOQ|FALHA" | tail -6 | sed 's/^/      /'; falhou=$((falhou+1)); fi; }
export BASE=/srv/sigarb PHP_BIN=php USUARIO_APP=sigarb DB_USUARIO_APP=rel_app DISCO_MINIMO_MB=512 TENTATIVAS_SAUDE=8
export URL_SAUDE=http://127.0.0.1:8099/api/v1/health
# "Reload do FPM": servidor embutido reiniciado no current (resolve o symlink a cada recarga, como o FPM com opcache resetado).
# A imagem não tem procps (pkill/pgrep): o servidor é controlado por PID em arquivo, e o reload só termina quando o
# processo novo está servindo a partir do diretório REAL da release ativa (senão a saúde mediria a release antiga).
cat > /usr/local/bin/recarregar-teste <<'RS'
#!/bin/bash
f=/run/sigarb-teste.pid
if [ -s $f ]; then kill "$(cat $f)" 2>/dev/null; for _ in $(seq 1 100); do kill -0 "$(cat $f)" 2>/dev/null || break; sleep 0.1; done; fi
alvo=$(readlink -f /srv/sigarb/current)/backend/public
cd "$alvo" && setsid -f su -s /bin/sh sigarb -c "echo \$\$ > /tmp/sigarb-teste.pid; exec php -S 127.0.0.1:8099 -t $alvo $alvo/index.php" </dev/null >/dev/null 2>&1
for _ in $(seq 1 100); do [ -s /tmp/sigarb-teste.pid ] && tr '\0' ' ' < /proc/$(cat /tmp/sigarb-teste.pid)/cmdline 2>/dev/null | grep -q " -t $alvo " && break; sleep 0.1; done
cp /tmp/sigarb-teste.pid $f; rm -f /tmp/sigarb-teste.pid
tr '\0' ' ' < /proc/$(cat $f)/cmdline | grep -q " -t $alvo " || { echo "reload de teste falhou" >&2; exit 1; }
for _ in $(seq 1 100); do curl -s -o /dev/null -m 1 http://127.0.0.1:8099/ && break; sleep 0.1; done
RS
chmod 755 /usr/local/bin/recarregar-teste
export RECARREGAR_FPM=recarregar-teste
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
falha_por "pacote com checksum externo divergente é recusado" "checksum do pacote" $OPS/implantar.sh /pacotes/sigarb-adulterada.tar.gz
falha_por "instalação com migrações pendentes exige --migrar" "migrações pendentes; repita com --migrar" $OPS/implantar.sh "$R1"
falha_por "--migrar sem backup verificado é recusado" "exige BACKUP_VERIFICADO" $OPS/implantar.sh "$R1" --migrar
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

# R2 traz migrações novas? (CP-10: sim). O fluxo muda: a atualização exige --migrar com backup verificado,
# e a reversão só de CÓDIGO para R1 fica bloqueada (banco à frente), devendo ser feita por restauração do backup.
lista_migr() { tar -tzf "$1" | sed 's#^[^/]*/##' | grep -E '^backend/database/migrations/.+\.php$' | sort; }
MIGRA2=0; [ -n "$(comm -13 <(lista_migr "$R1") <(lista_migr "$R2"))" ] && MIGRA2=1
ATUAL=$N1
if [ $MIGRA2 = 0 ]; then
echo "== Atualização (R2) e reversão"
rm -f $BASE/shared/migr.env
caso "implantação R2 sem migrações pendentes" sucesso $OPS/implantar.sh "$R2"
caso "current = R2" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N2 ]"
caso "reversão para R1" sucesso $OPS/reverter.sh
caso "current = R1 e saúde 200 após reversão" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N1 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
else
echo "== Atualização (R2 com migrações novas), interrompida e retomada"
falha_por "R2 com migrações novas exige --migrar" "migrações pendentes; repita com --migrar" $OPS/implantar.sh "$R2"
falha_por "R2 --migrar sem backup verificado é recusado" "exige BACKUP_VERIFICADO" $OPS/implantar.sh "$R2" --migrar
env BACKUP_VERIFICADO=TESTE-LOCAL $OPS/implantar.sh "$R2" --migrar >/dev/null 2>&1 & P=$!
for _ in $(seq 1 100); do ls -d $BASE/releases/.extraindo-* >/dev/null 2>&1 && break; sleep 0.05; done
kill -9 $P 2>/dev/null; wait $P 2>/dev/null
flock -w 120 $BASE/.implantacao.lock true
caso "após a interrupção, R1 segue ativo e saudável" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N1 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
rm -rf $BASE/releases/$N2
caso "implantação R2 com migração (backup verificado) conclui" sucesso env BACKUP_VERIFICADO=TESTE-LOCAL $OPS/implantar.sh "$R2" --migrar
rm -f $BASE/shared/migr.env
caso "current = R2 e saúde 200" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N2 ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
caso "R2: regras provisórias fora da lista de habilitadas (desligadas por padrão)" sucesso grep -qx 'operacoes_habilitadas=ENTRADA ESTORNO ' $BASE/current/MANIFESTO.txt
caso "R2: cadeia de auditoria íntegra após migrar" sucesso bash -c "cd $BASE/current/backend && su -s /bin/sh sigarb -c 'php artisan sigarb:auditoria:verificar' | grep -q íntegra"
falha_por "reversão só de código para R1 é bloqueada (banco migrado; reverter = restaurar backup)" "BLOQUEADO: o banco tem migrações" $OPS/reverter.sh "$N1"
caso "current continua R2 após a recusa" sucesso bash -c "[ \$(basename \$(readlink -f $BASE/current)) = $N2 ]"
ATUAL=$N2
fi
caso "upload preservado" sucesso grep -qx documento-sintetico $BASE/shared/storage/app/private/upload-teste.bin
caso ".env preservado" sucesso bash -c "[ \$(sha256sum $BASE/shared/.env | cut -d' ' -f1) = $H_ENV ]"

echo "== Injeção de falhas"
falha_por "release com saúde 500 é recusada" "saúde falhou após a troca" $OPS/implantar.sh /pacotes/sigarb-quebrada.tar.gz
caso "rollback automático registrado e release anterior ativa e saudável" sucesso bash -c "grep -q 'ROLLBACK AUTOMÁTICO DE CÓDIGO' $BASE/implantacao.log && [ \$(basename \$(readlink -f $BASE/current)) = $ATUAL ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
# Banco à frente do código: registro de migração futura (inserido pela identidade de MIGRAÇÃO, como faria uma release mais nova).
pdo_migr() { php -r '$p=new PDO("mysql:host=sigarb-dev-mysql;dbname=sigarb_rel","rel_migr",getenv("SM")); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->exec($argv[1]);' "$1" || { echo "ABORTADO: pdo_migr falhou ($1)"; exit 1; }; }
pdo_migr "INSERT INTO migrations (migration,batch) VALUES ('2099_01_01_000000_futura',99)"
falha_por "reversão bloqueada quando o banco tem migração desconhecida" "BLOQUEADO: o banco tem migrações" $OPS/reverter.sh "$([ $ATUAL = $N1 ] && echo $N2 || echo $N1)"
falha_por "implantação bloqueada quando o banco está à frente do código" "banco à frente do código" $OPS/implantar.sh "$R2"
pdo_migr "DELETE FROM migrations WHERE migration='2099_01_01_000000_futura'"

if [ $MIGRA2 = 0 ]; then
echo "== Implantação interrompida (SIGKILL durante a extração)"
rm -rf $BASE/releases/$N2
$OPS/implantar.sh "$R2" >/dev/null 2>&1 & P=$!
for _ in $(seq 1 100); do ls -d $BASE/releases/.extraindo-* >/dev/null 2>&1 && break; sleep 0.05; done
kill -9 $P 2>/dev/null; wait $P 2>/dev/null
flock -w 120 $BASE/.implantacao.lock true   # filhos órfãos (tar) seguram o lock até terminar: comportamento correto
caso "após a interrupção, current continua íntegro e saudável" sucesso bash -c "[ -d \$(readlink -f $BASE/current)/backend ] && [ \$(curl -s -o /dev/null -w '%{http_code}' $URL_SAUDE/ready) = 200 ]"
caso "reexecução conclui a implantação de R2" sucesso $OPS/implantar.sh "$R2"
fi
caso "nenhuma sobra de extração" sucesso bash -c "! ls -d $BASE/releases/.extraindo-* 2>/dev/null"
caso "segredos sem leitura por outros em toda a árvore" sucesso bash -c "r=\$(find $BASE -name .env -type f -perm /o=r; find $BASE -path '*bootstrap/cache/*.php' -perm /o=r); echo \"\$r\"; [ -z \"\$r\" ]"
echo "Histórico: $(tr '\n' ';' < $BASE/releases/HISTORICO)"
echo "Resultado: $ok ok, $falhou falha(s)"
[ $falhou -eq 0 ]
