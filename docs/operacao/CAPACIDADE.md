# Capacidade e gargalos — ambiente production-like LOCAL (CP-8)

> **Escopo desta evidência:** estação de desenvolvimento, dados **sintéticos**, contêineres com limites artificiais.
> Isto é **teste production-like local**. Não é homologação, não é aceite institucional e **não** autoriza produção.
> Nenhum número aqui pode ser extrapolado para "1.000" ou "5.000 usuários simultâneos".

## Níveis de prontidão (não confundir)
| Nível | Situação |
|---|---|
| Prontidão local (dev) | Atingida — suíte completa verde. |
| Teste production-like local | **Este documento.** Apache 2.4 event + PHP-FPM 8.5 (OPcache) + MySQL 8.4, limites de CPU/memória. |
| Prontidão para homologação | Pendente: ambiente institucional, TLS, dados e volumes reais, decisões P-xx. |
| Aceite institucional | Não iniciado. Nenhum requisito ACCEPTED. |
| Aprovação de produção | Não concedida. |

## Ambiente medido
- Apache 2.4.69 (event, 0,5 CPU/256 MiB) → `mod_proxy_fcgi` → PHP-FPM 8.5.12 `pm=static`, 12 filhos, OPcache, `request_terminate_timeout=35s` (2 CPU/1 GiB) → MySQL 8.4.13 (1 CPU/1 GiB, `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, binlog ligado).
- Host: 4 núcleos, 19 GiB, **compartilhado com outros processos** (load average de base 1–5).
- k6 1.3.0 em contêiner, rede do host. Script `scripts/carga-escalonada.sh` + `scripts/carga/escalonado.js`.
- Cenário `completo` = N VUs de leitura mista (painel, buscas, saldos, movimentações, referências, documentos, download) + 5 VUs escritores (ENTRADA no mesmo lote, com replay idempotente a cada 5ª).
- Evidências brutas: `.carga/` (fora do Git). Linha de base original preservada somente-leitura em `.carga/evidencia-base-20261106T0311/`.

## Causa raiz 1 — `enablereuse=on` no Apache (CONFIRMADA)
**Sintoma original (linha de base, 60 s por nível):**

| Nível | Reqs | Falhas | p50 | p95 | p99 | máx |
|---|---|---|---|---|---|---|
| 10 VUs | 2144 | 26 (**1,21%**) | 98 ms | 372 ms | 1,59 s | 35,04 s |
| 25 VUs | 2036 | 39 (**1,92%**) | 84 ms | 483 ms | 35,04 s | 35,05 s |
| 50 VUs | 1604 | 90 (**5,61%**, abortado) | 72 ms | 406 ms | 35,05 s | 35,07 s |

> Correção de relato: os níveis 10 e 25 foram inicialmente informados como "0% de erro". O correto é 1,21% e 1,92%
> (todos HTTP 504); passaram só porque o limiar de aborto era 5%.

**Evidência:**
1. O p99/máx de 35 s coincide exatamente com `ProxyTimeout 35` / `request_terminate_timeout 35s` — artefato de corte, não latência de aplicação.
2. Log do Apache: todos os 504 em `/csrf` (71) e `/auth/login` (59) — a **primeira** requisição de cada sessão, em conexão TCP nova. Repetição com o harness novo: `/csrf` 43 × 504 em 68, todos a 35 041–35 055 ms; todas as demais rotas com p99 < 1,1 s.
3. Log do FPM: 47 workers terminados `execution timed out (35 s)` com `script '' (request: " ")` — filhos presos a uma conexão **sem requisição**, inclusive **depois** do fim do teste.
4. Só 13 dos 55 VUs conseguiram login; os 401/419 restantes são **cascata** (sem cookie CSRF → login 419 → leituras 401), não defeitos independentes.

**Mecanismo:** com `enablereuse=on`, cada thread do Apache (até `MaxRequestWorkers=200`) mantém uma conexão persistente com um filho do FPM; o filho fica dedicado e ocioso à espera daquela thread. Com 12 filhos, conexões novas esperam no backlog do FPM até 35 s.

**Correção (variável única, A/B):** removido `enablereuse=on` de `infra/prodlocal/apache/sigarb.conf`. Resultado imediato a 50 VUs: **0 × 504, 0% de falhas, 55/55 logins** (execução `depois-reuse`). Expôs a causa 2.

## Causa 2 — rajada de login no harness (CONFIRMADA; defeito de medição)
Sem rampa, os 55 VUs fazem login no mesmo segundo. bcrypt (rounds 12, **não reduzido**) é CPU-bound: app a **200% (= limite de 2 CPU)**, 12/12 filhos ativos, fila do FPM 44 (máx. 99), login p50 6,8 s → aborto por p95 > 5 s. Isso mede uma rajada artificial, não operação contínua. Correção: rampa de subida de 30 s (`RAMPA`), padrão em testes de carga. A rajada de login é medida separadamente (`CENARIO=login RAMPA=0s`): 20 logins simultâneos → p50 2,6 s, p95 4,4 s, CPU do app 197%.

## Causa 3 — espera dupla de lock em escritas no mesmo lote (CONFIRMADA; corrigida)
Slow log nas escritas: `insert ignore into saldos_estoque` acumulou 60,8 s de lock, mais que o `FOR UPDATE` da cadeia de auditoria (50,6 s) e do saldo (39,7 s). Em InnoDB, `INSERT IGNORE` sobre chave **existente** adquire lock compartilhado no registro e espera o `FOR UPDATE` de outra transação: cada escritor esperava duas vezes. Correção em `EstoqueService::garantirSaldo`: leitura consistente sem lock (`exists()`) e `insertOrIgnore` só se a linha não existir. A garantia continua sendo o `FOR UPDATE` dentro da transação; adicionado fail-closed explícito se a linha não existir.

| Cenário (20 VUs, 30 s, rampa 10 s) | Antes | Depois |
|---|---|---|
| Escrita no **mesmo lote** | 19,6 req/s · p50 307 ms · p95 1,52 s · 0% | **27,0 req/s · p50 187 ms · p95 0,48 s · 0%** |
| Escrita em **lotes independentes** | 23,6 req/s · p95 1,15 s · 0% | 24,0 req/s · p95 1,10 s · 0% (inalterado, esperado) |

## Gargalos remanescentes (medidos, NÃO alterados)
- **CPU do contêiner app (2 CPU)** — limita leituras e logins. A 50 VUs: CPU média 154–174%, 11,1/12 filhos ativos em média, fila do FPM até 43. Mais filhos FPM **não** ajudariam (CPU esgotada); aumento cego proibido.
- **Serialização global da cadeia de auditoria** (ADR-007) — com lotes independentes o teto de escrita (~24 req/s, ~20 entradas/s) não muda: o `FOR UPDATE` em `auditoria_cadeia` é o limite. É compromisso de projeto (hash encadeado, fail-closed). Alterar exige decisão institucional; **não** foi alterado.
- **MySQL**: 45–58% de 1 CPU; 0 deadlocks em todas as execuções; `max_used_connections` ≤ 15 de 120.

## Cenários isolados (20 VUs, 30 s, antes da correção 3)
| Cenário | req/s | p50 | p95 | Erros | CPU app | CPU MySQL |
|---|---|---|---|---|---|---|
| Leitura | 49,5 | 189 ms | 529 ms | 0% | 143% | 46% |
| Login (rajada, sem rampa) | — | 2,59 s (login) | 4,40 s | 22,3%* | 197% | 9% |
| Escrita mesmo lote | 19,6 | 307 ms | 1,52 s | 0% | 116% | 41% |
| Escrita lotes independentes | 23,6 | 262 ms | 1,15 s | 0% | 128% | 42% |

\* Os 58 "erros" do cenário login são **defeito do cenário** (chamava `/auth/me`, rota inexistente → 404); os 64 logins tiveram 100% de sucesso. Corrigido para `/me`.

## Resultado final a 50 VUs (cenário completo, 60 s + rampa 30 s)
| Execução | Reqs | Falhas | req/s | p50 | p95 | p99 | máx | Entradas 201 = Δ banco | Replays 200 |
|---|---|---|---|---|---|---|---|---|---|
| Linha de base | 1604 | 5,61% (abortado) | 40,1 | 72 ms | 406 ms | 35,05 s | 35,07 s | — | — |
| Final r1 | 4463 | **0,00%** | 47,4 | 757 ms | 1,59 s | 2,00 s | 3,60 s | 196 = 196 | 42 |
| Final r2 | 4387 | **0,00%** | 46,2 | 777 ms | 1,64 s | 2,54 s | 4,12 s | 189 = 189 | 40 |

A mediana maior que na linha de base **não** é regressão: na linha de base só ~12 VUs estavam de fato ativos (o resto preso em 504); agora os 55 VUs trabalham e o app opera no teto de CPU.

**Integridade após cada execução:** 0 saldos negativos; 0 chaves de idempotência duplicadas; saldo do lote = Σ itens de entrada; `sigarb:auditoria:verificar` → "Cadeia de auditoria íntegra"; 0 deadlocks; nenhum coletor ou contêiner k6 órfão.
Exceção explicada: execução abortada `depois-reuse` tem 10 entradas no banco para 8 respostas 201 — 2 requisições confirmadas no servidor durante o aborto do k6, sem resposta registrada. Não é perda nem duplicação.

## 100 VUs — NÃO executado (decisão deliberada)
A 50 VUs o load average do host chegou a 9,95–12 em 4 núcleos, com outros processos ativos na estação. Como o app já opera no teto de CPU, 100 VUs só aumentariam fila e latência, sem revelar capacidade nova, e arriscariam interferir em outros processos. Critério da diretiva: só avançar com uso de recursos controlado.

## Confiabilidade do harness (corrigida e testada)
Defeito original: com `set -e`, a falha do k6 encerrava o script antes de parar o coletor de recursos, que ficou órfão ~4 h (só leituras leves), e o script apagava a evidência anterior (`rm -rf`). Agora: diretório novo por execução; `trap` em EXIT/INT/TERM para coletor e contêiner k6 nomeado; `STATUS-N` (COMPLETO / FALHOU_LIMIAR / FALHOU_K6 / INTERROMPIDO); saída ≠ 0 quando um nível falha; registro de SHA, configuração e durabilidade (`execucao.txt`); requisições individuais (`requisicoes-N.csv.gz`).
Testes: falha intencional (porta fechada) → `FALHOU_LIMIAR`, sem órfãos; SIGTERM no meio do k6 → `INTERROMPIDO rc=143`, sem órfãos.

## Recuperação e saúde (CP-8)
- **Defeito corrigido:** com o banco fora do ar, liveness e readiness retornavam **500**: as sondas atravessavam sessão (`SESSION_DRIVER=database`) e throttle/cache (`CACHE_STORE=database`). Os testes não pegavam porque usam `CACHE_STORE=array`. Correção: `routes/saude.php` fora do grupo `web` (sem sessão/cookies/CSRF); limite de taxa (`LimiteSaude`) e cache de 5 s em store **local** (`SIGARB_SAUDE_CACHE`, padrão `file`). Teste de regressão com drivers de produção e banco inacessível.
- Production-like: banco parado → **live 200, ready 503** (`banco: falha`); banco de volta → 200/200 sem intervenção.
- Disco baixo **real** (tmpfs 16 MB no diretório de documentos) → **503** `espaco_em_disco: falha`; controle com 1 GB → 200.
- Backup/restauração: 19/19, incluindo triggers bloqueando UPDATE/DELETE (`SQLSTATE 45000`) no banco restaurado e restauração interrompida por SIGTERM sem deixar banco ou diretório para trás.

## Validação final
Composer audit não executável desta rede (packagist.org inacessível) → merge do CP-8 NO-GO; nenhuma medição foi repetida. Requisitos de homologação: `PRONTIDAO-HOMOLOGACAO.md`.

## Limites desta evidência
Uma estação, um nó de app, rede local, volumes de dados pequenos, cliente de carga no mesmo host, carga do host variável. Sem TLS, sem proxy institucional, sem latência de rede real. Capacidade institucional exige teste no ambiente de homologação com perfil de uso real (P-xx).
