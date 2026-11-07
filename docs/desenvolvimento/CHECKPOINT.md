# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-07 (CP-10)
- **CP-8 integrado:** merge `--no-ff` em main **`d3ea7dd`**; suíte completa no main mesclado exit 0; publicado `ff0eb04..d3ea7dd` (push normal via SSH porta 443 — porta 22 bloqueada nesta rede).
- **Formatação:** branch `chore/php-pint-standardization` (`f9e531a` formatação de 58 arquivos, equivalência de tokens verificada com controle negativo; `5c5097c` Pint como gate). Pipeline completo verde; **aguardando autorização de merge**.
- **Fase atual:** CHECKPOINT 8 — runtime production-like, gargalos e recuperação — **concluído no escopo local**.
- **Status geral:** MVP local. **Não aprovado para produção. Nada implantado. Nenhum requisito ACCEPTED.**

## CP-10 — Fluxos operacionais com regra provisória (branch `feat/fluxos-operacionais-provisorios`, base `feat/release-piloto` + CP-9)
- Decisão do responsável do projeto (2026-11-06): implementar os processos bloqueados com regras **provisórias**, desligadas por padrão (`SIGARB_OPERACOES_PROVISORIAS`), sem marcar requisitos como aceitos.
- Estoque: saída, baixa, transferência em dois tempos, reserva/liberação, manutenção/retorno, entrega com/sem retorno e devolução (`OperacaoEstoqueService`, `vinculo_id` único).
- Acautelamento: solicitação → decisão (quem pede não decide) → entrega → inspeções (imutáveis) → desacautelamento; carteira para impressão com QR de token opaco, verificação só com sessão.
- Inventário: saldo esperado congelado, contagem, ajuste de lotes aprovado por quem não abriu nem contou (registro append-only de contadores).
- Alertas calculados no escopo, exportação CSV auditada (não oficial), ficha técnica de armamento; "Militares" passa a "Efetivos" na interface.
- Revisão de segurança automática: 5 achados, todos corrigidos com teste (item que mudou de unidade, matrícula na carteira, contadores do inventário, backfill).
- Matriz: RF-007/011/012/015..023 e RN correspondentes em IN_PROGRESS. **Nenhum requisito ACCEPTED.**

## Release e piloto (branch `feat/release-piloto`, de main local `2a05028`) — não mesclado, não publicado
- `deploy/construir-release.sh`: pacote **reprodutível** (git archive do SHA, locks fixados, imagens por digest, tar determinístico; dois builds independentes → mesmo SHA-256), manifesto com SHA/schema/operações habilitadas, SHA256SUMS, sem testes/.env/segredos/DOCX/uploads.
- `deploy/ops/{preflight,implantar,reverter}.sh` + `deploy/modelos/` (vhost sem enablereuse, pool FPM dedicado, privilégios .9, .env, logrotate) + `deploy/RUNBOOK.md`.
- `scripts/testar-implantacao.sh`: **26/26** (simulação, checksum, migração só com backup verificado, cache de configuração 0640, atualização, reversão, rollback automático em saúde 500, banco à frente do código bloqueado, SIGKILL durante extração, uploads/.env preservados).
- Defeitos encontrados pelos testes e corrigidos: cache de config legível por todos; lock herdado pelo reload; preflight com falso bloqueio por SIGPIPE; **banco à frente do código não detectado** (`migrate:status`).
- Piloto: `docs/operacao/PILOTO-SUPERVISIONADO.md`. **NO-GO na VM** (Portão A da Infra; preflight institucional 2026-11-06).
## CP-9 — Interface institucional e Central de Suporte (branch `feat/interface-institucional-central-suporte`, de main local `2a05028`)
- **Não mesclado.** Não faz parte da release candidata do piloto (`02bc501`, branch `feat/release-piloto`).
- Design system alinhado ao Sistema-de-escalas (`prbtechal/Sistema-de-escalas` @ `849b07c`): `docs/desenvolvimento/DESIGN-SYSTEM.md`.
- Menu lateral recolhível (hover/foco), gaveta móvel com foco preso, barra de conta com notificações e tema, marca SIGARB provisória.
- Central de Suporte (backend + frontend): `docs/seguranca/SUPORTE.md`; migração `2026_11_07_000100_create_suporte_tables` (3 tabelas, 4 triggers).
- Console de auditoria: categorias, request_id, ator, detalhe completo, integridade.
- Requisito **fora do SRS** (pedido do usuário): sem ID RF; não altera a matriz de rastreabilidade.

## Níveis de prontidão
Prontidão local: atingida · Teste production-like local: **realizado (CP-8)** · Homologação: pendente (ambiente institucional) · Aceite institucional: não iniciado · Produção: não aprovada.

## Entregas do CP-8 (detalhes: docs/operacao/CAPACIDADE.md)
- Ambiente `infra/prodlocal` (Apache event + FPM 12 filhos + OPcache + MySQL 8.4, durabilidade total).
- Causa raiz das falhas de carga: `enablereuse=on` (removido). Rampa de 30 s no harness (rajada de login medida à parte). `garantirSaldo` sem lock compartilhado redundante (+37% em escrita no mesmo lote).
- 50 VUs: **0% de erro**, ~47 req/s, p95 1,59/1,64 s em 2 repetições; integridade e cadeia de auditoria íntegras. 100 VUs **não** executado.
- Saúde: sondas fora do grupo `web`; banco fora → live 200/ready 503; disco baixo real → 503.
- Backup/restauração: 19 casos (triggers funcionais após restauração; restauração interrompida).
- Harness de carga confiável (sem órfãos, sem apagar evidência, STATUS por nível).
- Incidente local de exposição de segredos de DEV na sessão: segredos rotacionados (ver HISTORICO).

## Validação final do CP-8 (2026-11-06) — merge **GO** após auditoria completa (tabela abaixo: 1ª tentativa, NO-GO)
| Gate | Resultado |
|---|---|
| Backend | **112 passed, 810 assertions** (inclui concorrência reforçada em lote novo) |
| Vitest | **24 passed** |
| Build | OK |
| E2E Chromium | **19 passed, 6 skipped** |
| Backup/restauração | **19/19** |
| npm audit (prod e todas) | **0 vulnerabilidades** |
| **Composer audit** | **NÃO EXECUTADO** — `packagist.org` (API de advisories, 46.151.194.243) inacessível desta rede: IPv4 timeout, IPv6 recusado; `repo.packagist.org` responde. Não é advisory nem lockfile. |
| `scripts/test.sh` | **exit 2** (auditoria incompleta) |
| Pint | `OrganizacaoEPermissoesTest.php` corrigido (só import). **~60 arquivos de CP-1..CP-7 ainda divergem**; Pint nunca foi gate do pipeline. Relato anterior de "uma divergência" estava errado. |
| Remoto | não verificável agora (SSH timeout; HTTPS sem credencial). origin = `git@github.com:prbtechal/SIGARB.git` |

**Atualização (2026-11-06 11:05):** causa isolada — DNS rotativo do packagist.org entrega IPs de CDN inalcançáveis por esta rede (46.151.194.243, 138.199.4.178; 169.150.220.228 responde) e a rede bridge do Docker não resolve DNS. `auditar-dependencias.sh` passou a usar a rede do host (mesma imagem, fonte e TLS). **Composer: 0 advisories (prod e dev), 0 abandonados. `scripts/test.sh` exit 0** (112/810, 24, build, E2E 19/6, backup 19/19, npm 0). Linha de base de formatação: ~60 arquivos legados fora do padrão Pint — tratados em branch separado, não declarados conformes aqui.

**Decisão inicial:** sem merge, sem push. Desbloqueio: rodar `scripts/auditar-dependencias.sh` numa rede que alcance `packagist.org` (resultado 0), e decidir sobre a formatação em massa (commit separado, só formatação).
Prontidão para homologação: `docs/operacao/PRONTIDAO-HOMOLOGACAO.md`.

## Pendências e decisões institucionais
- Metas de desempenho e perfil de uso (P-10); capacidade no ambiente de homologação.
- Teto de escrita definido pela cadeia global de auditoria (ADR-007): mudar exige decisão.
- Leitor de tela (acessibilidade) pendente; TLS/HSTS e proxy institucional na homologação.

---
# Registro anterior (CP-7)

## Publicação no GitHub — PENDENTE
- Repositório `prbtechal/SIGARB` verificado: **privado**, **vazio**, sem rulesets disponíveis; acesso prbtechal (admin) e joseook (write).
- Auditoria do histórico do main: **aprovada** (DOCX nunca versionado; 0 ocorrências dos segredos locais; sem chaves, dumps, logs, builds ou uploads). Aviso: IPs institucionais .8/.9 na documentação (aceitável só por ser privado).
- **Push não executado**: bloqueado pela política de permissões da sessão do agente. O remoto **não** está configurado localmente.
- Para publicar (pelo usuário): `git -C <SIGARB> remote add origin https://github.com/prbtechal/SIGARB.git && git -C <SIGARB> push -u origin main`. Somente main; branches de desenvolvimento não devem ser enviados.

## Entregas do CP-7
- **A. Logs estruturados** (RNF-14 TESTED): JSON por requisição, request_id correlacionado com resposta e auditoria, sem dados sensíveis.
- **B. Saúde** (RNF-09): `/api/v1/health/live` e `/health/ready` (banco, disco privado, espaço), mínimas, com cache e limite de taxa.
- **C. Backup/restauração local cifrados** (RF-030, RNF-15 em andamento): GnuPG AES-256, chave de DEV fora do repositório, restauração em banco descartável com verificação completa; 11 casos automatizados.
- **D. Carga local** (RNF-07): k6 isolado, 0% erro, p95 1,44 s; integridade preservada sob concorrência; **não** é capacidade de produção.
- **E. Acessibilidade**: verificações sem axe nos 3 motores; investigação do Firefox concluída (axe.run derruba o Firefox 159); leitor de tela **pendente**.
- **Protótipos PE-06/PE-07**: mantidos isolados; ressalvas acrescentadas (amostra limitada; chaves só em memória).

## Requisitos (ver MATRIZ-RASTREABILIDADE.md)
- Totais: **37 TESTED · 14 IN_PROGRESS · 3 IMPLEMENTED · 27 BLOCKED · 4 NOT_STARTED · 0 ACCEPTED**.
- CP-7: RNF-14 → TESTED; RF-030, RNF-09, RNF-15 NOT_STARTED → IN_PROGRESS; RNF-07 com evidência de carga; RF-028 com novas verificações.

## Testes (resultado exato, 2026-11-06T02:45:05-03:00)
| Suíte | Resultado |
|---|---|
| Backend | **111 passed, 0 failed, 794 assertions** |
| Vitest | **24 passed** |
| Build | OK |
| E2E Chromium (test.sh) | **19 passed, 6 skipped** |
| Multi-navegador | Chromium+Firefox **35/8 skipped**, WebKit **19/6 skipped**, 0 failed |
| Backup/restauração | **11/11** |
| Carga (k6) | 1481 req, **0% erro**, p95 1,44 s; integridade íntegra |
| Dependências | 0 vulnerabilidades |
| `scripts/test.sh` | **exit 0** |

## Migrações
Nenhuma nova no CP-7.

## Achados e disposição
- Script de carga inicialmente inválido (k6 zera cookies por iteração; numeração global de VUs) — **corrigido antes de qualquer conclusão**; resultados com 83% de erro descartados.
- Premissas erradas em testes de foco (ponto de partida da navegação; destino do skip link) — corrigidas; comportamento da aplicação estava correto.
- MySQL de dev chegou a 740/768 MiB sob carga — limite do container de desenvolvimento, não de produção.

## Limitações conhecidas
Firefox 159 sem axe (compensado); leitor de tela não executado; carga não representativa; inspeção de PDF heurística; sem antivírus/cifragem em repouso; backup sem agendamento nem destino institucional.

## Decisões institucionais pendentes
P-01…P-12 (em especial P-08 retenção, P-10 RPO/RTO/metas de desempenho), PE-05, PE-06, PE-07, navegadores institucionais (RNF-12), **autorização de execução do push** (bloqueado na sessão do agente).

## Próxima tarefa exata
1. Usuário executa (ou autoriza na configuração de permissões) o push do main e confirma o SHA remoto.
2. Decidir merge de `feat/checkpoint-7-observabilidade-recuperacao` em main.
3. CP-8 item 1: imagem candidata PHP-FPM + OPcache em Compose local e nova rodada de carga.

## Comandos seguros para revalidar
```bash
scripts/test.sh                  # inclui E2E, backup e auditoria de dependências
scripts/e2e-navegadores.sh       # Chromium, Firefox, WebKit
scripts/verificar-privilegios.sh
scripts/teste-carga.sh           # carga local (pesado; não rodar junto com outras suítes)
```

## Árvore de trabalho
Limpa após este commit. Fora do repositório: `~/.config/sigarb-dev/backup.chave` (DEV, 600) e `~/.local/share/sigarb-dev/backups/` (backups sintéticos).

## Produção / implantação
**Nada implantado. Nenhum remoto configurado. Nenhum acesso às VMs.** RondaSocial, Visita-Segura e Sistema-de-escalas não foram tocados.
