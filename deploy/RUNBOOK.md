# SIGARB — Runbook de implantação versionada (VM .8 app / VM .9 banco)

> **Não executar sem:** aprovação de execução, Portão A da Infra = GO (relatório de preflight institucional),
> janela avisada ao responsável do Sistema-de-escalas. Nada aqui altera Escalas, Visita-Segura ou RondaSocial.
> SIGARB é aplicação **nova**: schema limpo, sem migração de dados legados. Piloto usa dados **sintéticos**.

## 0. Pré-requisitos bloqueantes (evidência da Infra, antes de qualquer passo)
| Item | Exigido | Estado no preflight 2026-11-06 |
|---|---|---|
| NTP | `timedatectl` NTPSynchronized=yes em .8 e .9 | **não sincronizado** |
| Disco .8 | ≥ 30 GB livres (A-02) | **6,8 GB** |
| MySQL .9 | bind 192.168.200.9; 3306 só de .8 + admin | **0.0.0.0** |
| TLS/DNS | `sigarb.prb.intra` resolvendo; certificado da AC institucional | **inexistente** |
| Segredos | `.env`/dumps sem leitura por outros nos hosts | **644 em .8/.9** (Escalas) |
| Backup | destino cifrado externo (A-11/A-12) + restauração isolada registrada | **sem evidência** |
| Operador | `sudo` restrito para as etapas abaixo | **sem sudo -n** |

## 1. Preparação única (Infra, com sudo)
```
useradd -r -s /usr/sbin/nologin -d /var/www/app/sigarb sigarb
install -d -o root -g root -m 0755 /var/www/app/sigarb /var/www/app/sigarb/releases
install -d -o root -g sigarb -m 0750 /var/www/app/sigarb/shared
install -d -o sigarb -g sigarb -m 0750 /var/www/app/sigarb/shared/storage /var/www/app/sigarb/shared/storage/{framework,framework/cache,framework/sessions,framework/views,logs}
install -d -o sigarb -g sigarb -m 0700 /var/www/app/sigarb/shared/storage/app /var/www/app/sigarb/shared/storage/app/private
install -o root -g sigarb -m 0640 modelos/env.modelo /var/www/app/sigarb/shared/.env   # preencher segredos NOVOS
install -d -o sigarb -g sigarb -m 0750 /var/log/php-fpm
```
Agendador do Laravel (PSI-PRB 9.3 — desativação diária de contas sem acesso há 60 dias), como `sigarb`:
```
# /etc/cron.d/sigarb
* * * * * sigarb php8.5 /var/www/app/sigarb/current/backend/artisan schedule:run >> /dev/null 2>&1
```
Antes de ligar, conferir quem seria desativado: `sudo -u sigarb php8.5 …/artisan sigarb:contas:desativar-inativas --simular`.
Logrotate: instalar `modelos/logrotate-sigarb` (180 dias, PSI-PRB 31.3).

DBA em .9: `modelos/privilegios-vm.sql` (senhas geradas na hora; `sigarb_migr` fica `ACCOUNT LOCK`).

## 2. Transferência e verificação do pacote
```
scp sigarb-<sha12>.tar.gz sigarb-<sha12>.tar.gz.sha256 jose@192.168.200.8:/tmp/
sha256sum -c sigarb-<sha12>.tar.gz.sha256          # na VM; deve bater com o checksum publicado no relatório
tar -xzf sigarb-<sha12>.tar.gz -C /tmp && cd /tmp/sigarb-<sha12>
```

## 3. Simulação (não altera nada)
```
sudo BASE=/var/www/app/sigarb ops/implantar.sh /tmp/sigarb-<sha12>.tar.gz --simular
```
Esperado: `PREFLIGHT_OK`, migrações pendentes listadas (instalação nova: todas), `SIMULACAO_OK`.

## 4. Configuração de serviço (janela avisada; reload, nunca restart)
```
install -m 0644 modelos/fpm-sigarb.conf /etc/php/8.5/fpm/pool.d/sigarb.conf && php-fpm8.5 -t
install -m 0644 modelos/apache-sigarb.conf /etc/apache2/sites-available/sigarb.conf && a2ensite sigarb && apachectl configtest
install -m 0644 modelos/logrotate-sigarb /etc/logrotate.d/sigarb
# Linha de base do Escalas ANTES (hash da listagem de config + /escalacerta/up 200)
systemctl reload php8.5-fpm && systemctl reload apache2
# Linha de base do Escalas DEPOIS: só sigarb.conf e pool.d/sigarb.conf novos; /escalacerta/up 200
```

## 5. Primeira implantação (com migração)
1. DBA: `ALTER USER 'sigarb_migr'@'192.168.200.8' ACCOUNT UNLOCK;`; Infra cria `shared/migr.env` (600 root).
2. Backup do schema (vazio na 1ª vez) + restauração em schema descartável → identificador `BKP-...`.
3. `sudo BASE=/var/www/app/sigarb BACKUP_VERIFICADO=BKP-... ops/implantar.sh /tmp/sigarb-<sha12>.tar.gz --migrar`
4. Remover `shared/migr.env`; DBA: `ACCOUNT LOCK` em `sigarb_migr`.
5. Dados sintéticos do piloto: ver `docs/operacao/PILOTO-SUPERVISIONADO.md` (repositório).

## 6. Atualizações e rollback
- Nova release: passos 2, 3 e `ops/implantar.sh <pacote>` (sem `--migrar` se não houver migração).
- Saúde falhou após a troca → **rollback automático de código** para a release anterior (o banco não é tocado).
- Rollback manual: `sudo BASE=… ops/reverter.sh [release]`. **Bloqueado** se o banco tiver migrações que a release alvo não conhece: nesse caso, restaurar o backup feito antes da migração (decisão do DBA + responsável institucional).
- Uploads (`shared/storage/app/private`) e `.env` nunca fazem parte da release; sobrevivem a qualquer troca.
- Implantação interrompida: reexecutar o mesmo comando (lock exclusivo; extração temporária descartada; troca atômica).

## 7. Verificação pós-implantação
```
curl -s https://sigarb.prb.intra/api/v1/health/live    # 200
curl -s https://sigarb.prb.intra/api/v1/health/ready   # 200, banco/armazenamento/espaço ok
sudo -u sigarb php8.5 /var/www/app/sigarb/current/backend/artisan sigarb:auditoria:verificar
cat /var/www/app/sigarb/current/MANIFESTO.txt          # SHA e schema registrados no relatório da janela
```

## Regras provisórias (a partir de `feat/fluxos-operacionais-provisorios`)
- A release sai com **todas as regras provisórias desligadas** (`SIGARB_OPERACOES_PROVISORIAS=` vazio): as operações respondem `409 OPERACAO_BLOQUEADA`, como antes.
- Ligar exige autorização registrada; depois de alterar o `.env`, rodar `php artisan config:cache` e recarregar o FPM (mesmo procedimento da implantação).
- Desligar não apaga nada: registros já feitos permanecem (marcados `provisoria = 1`), pendências abertas ficam visíveis e só podem ser concluídas quando a operação for religada.
- Migrações novas: `2026_11_08_000100` a `000500` (operações, acautelamento, inventário, contadores). Todas têm `down()`; a reversão segue o fluxo padrão (backup verificado antes de migrar).

## Exceção de NTP (registrada)
Se a fonte de hora estiver fora do ar (ex.: 2026-11-18: chrony da .8/.9 apontava para a 192.168.200.4, desligada), o preflight bloqueia a implantação.
Com autorização do responsável e o relógio conferido à mão, a implantação pode seguir com `SIGARB_EXCECAO_NTP="<data> <quem autorizou> <motivo>"`:
o item vira PEND e o motivo fica no relatório. Pendência: apontar o chrony da .8 e da .9 para uma fonte NTP válida.

## 8. Release com a migração de fuso (`2026_12_01_000100_fuso_america_maceio`, ADR-015)
Converte o histórico UTC para a hora de Maceió (−3 h) em todas as colunas de data/hora, uma única vez.
1. **Janela sem operação** (fora do expediente; avisar armeiros): o `implantar.sh` migra com a release antiga no ar e o que ela gravar durante a migração fica 3 h adiantado.
2. Backup do schema **imediatamente antes** + restauração em schema descartável → `BACKUP_VERIFICADO=BKP-...` (é o único rollback).
3. `.env`: nada obrigatório (padrões `APP_TIMEZONE=America/Maceio`, `DB_TIMEZONE=-03:00`); se definidos, os dois têm de concordar.
4. Antes: anotar `SELECT MAX(id), MAX(ocorrido_em) FROM eventos_auditoria;`, `MAX(ocorrido_em)` e `MAX(created_at)` de `movimentacoes` e a lista de `information_schema.TRIGGERS` do schema.
5. `ops/implantar.sh … --migrar` (o `sigarb_migr` precisa de TRIGGER, já incluso em `ALL PRIVILEGES`). Um UPDATE por tabela (~75 colunas em ~45 tabelas); com o volume atual do piloto, aproximadamente segundos.
6. Depois: `artisan sigarb:auditoria:verificar` = íntegra; `SELECT * FROM conversao_fuso;` = todas CONVERTIDA e `*` CONCLUIDA; os máximos do passo 4 estão 3 h menores; lista de gatilhos igual à anterior; `health/ready` com `"fuso":"ok"`.
7. Rollback: **restaurar o backup do passo 2** e reverter o código (`ops/reverter.sh` fica bloqueado pela migração, de propósito). Não usar `migrate:rollback` (o `down()` recusa).
