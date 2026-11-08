# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-08 (CP-14)
- **CP-8 integrado:** merge `--no-ff` em main **`d3ea7dd`**; suíte completa no main mesclado exit 0; publicado `ff0eb04..d3ea7dd` (push normal via SSH porta 443 — porta 22 bloqueada nesta rede).
- **Formatação:** branch `chore/php-pint-standardization` (`f9e531a` formatação de 58 arquivos, equivalência de tokens verificada com controle negativo; `5c5097c` Pint como gate). Pipeline completo verde; **aguardando autorização de merge**.
- **Fase atual:** CHECKPOINT 8 — runtime production-like, gargalos e recuperação — **concluído no escopo local**.
- **Status geral:** MVP local. **Não aprovado para produção. Nada implantado. Nenhum requisito ACCEPTED.**

## CP-14 — P-02/P-06/P-07 decididas e Armeiro completo (branch `feat/cp14-politicas-finais-armeiro`, base CP-13) — TESTED, não mesclado
- **Decisões do responsável (2026-11-08)** em `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md` (sem aceite institucional): P-02 (árvore genérica, escopo por subárvore, estrutura real cadastrada pela instituição, transferência com recebimento no destino), P-06 (Aprovador/Gestor/Armeiro aprovam, nunca o próprio pedido, rejeição justificada, cancelamento antes da entrega, delegação por papel com prazo), P-07 (carteira com QR opaco, válida enquanto ativa e com inspeção em dia, verificação com login).
- **Portão:** todas as 13 operações HABILITADA. O mecanismo (ADR-006) continua testado: `TestCase::bloquear()` simula operação pendente nos testes que exigiam bloqueio.
- **Armeiro:** passa a fazer baixa, saída, transferência e aprovações (acautelamento e inventário); não cadastra efetivos. **Gestor** também aprova. Migração `2026_11_14_000200` para bancos implantados.
- **Delegação (P-06):** `atribuicoes_papel.expira_em` (migração `2026_11_14_000100`); vencida não concede permissão e pode ser renovada; campo "Válido até" em Usuários e acessos.
- **Carteira (P-07):** `valida_ate` = próxima inspeção; QR responde `INSPECAO_VENCIDA`.
- **P-02:** importação/exportação de unidades (com superior na mesma planilha) e locais de estoque.
- **Evidências (Linux, 2026-11-08):**
  - Pint 174 arquivos OK; PHPUnit **170/170 (1457 asserções)**, `Cp14PoliticasFinaisTest` 6/6. 9 testes antigos que exigiam bloqueio passaram a usar `bloquear()` (mesmas asserções de 409 OPERACAO_BLOQUEADA); Cp13 "Armeiro não faz baixa" passou a "Operador sem permissão não faz baixa".
  - `tsc -b` limpo; Vitest **25/25**; build OK. **E2E 28 passed / 0 failed / 11 skipped.**
  - Backup **19/19**; dependências 0 vulnerabilidades; implantação R1 `50ff2d8` → R2 `96b3b81` **29/29** (manifesto exige as 13 operações habilitadas).
  - Migração em banco populado (seed do main → 9 migrações CP-12/13/14): IDs e contagens idênticos, cadeia íntegra; rollback do CP-14 e depois de CP-13+12 sem perda; reaplicação OK.
- **Única pendência ainda bloqueada:** P-12 (relatórios oficiais: RF-023, UC-13). Abertas sem bloqueio: P-01, P-04 (lista oficial, cadastrável), P-08, P-10, P-11.

## CP-13 — Armeiro, políticas P-03/P-05/P-09 e importação por planilha (branch `feat/cp13-armeiro-importacao-politicas`, base CP-12) — TESTED, não mesclado
- **Decisões do responsável (2026-11-08):** P-03, P-05 e P-09 delegadas à engenharia e registradas em `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md` (decisão de projeto, **sem aceite institucional**). P-04 segue aberta: a lista oficial será cadastrada pela instituição (tela ou planilha).
- **Portão:** SAÍDA, ENTREGA, DEVOLUÇÃO, RESERVA, BAIXA, MANUTENÇÃO e INSPEÇÃO passam a HABILITADA. TRANSFERÊNCIA (P-02), INVENTÁRIO, ACAUTELAMENTO e DESACAUTELAMENTO (P-06/P-07) seguem só como regra provisória desligada.
- **P-03:** material vencido (`sigarb.politicas.bloquear_vencido`) ou INSERVIVEL não é entregue nem reservado (409 ITEM_VENCIDO/ITEM_INSERVIVEL).
- **P-05:** devolução parcial por item/quantidade com estado de conservação (migração `2026_11_12_000100`, `vinculo_parcial_id`; a devolução final continua no `vinculo_id` UNIQUE). Detalhe da entrega traz `pendente_devolucao`.
- **Papel ARMEIRO** (migração `2026_11_12_000200`, também para bancos implantados): cadastra material, entradas, entrega/devolução, manutenção, reserva, importa e exporta; sem baixa, saída nem aprovação. Usuário de demonstração `armeiro.alfa`.
- **Níveis de proteção** administráveis (migração `2026_11_12_000300`, lista em Referências; nome imutável em uso).
- **Importação/exportação CSV** (`/data-transfer`, tela `/dados`): 8 cadastros, modelo, simulação, tudo ou nada, mesmas validações/escopo/auditoria da tela; colunas fora do modelo recusadas (P-09). Guia: `docs/operacao/IMPORTACAO-EXPORTACAO.md`.
- **Revisão de segurança automática:** 2 achados na importação, corrigidos com teste: oráculo de existência de local/unidade fora do escopo; negações de acesso perdidas no rollback.
- **Evidências (Linux, 2026-11-08):**
  - Pint `--test` 171 arquivos OK; PHPUnit **164/164 (1401 asserções)**, `Cp13ArmeiroImportacaoTest` 9/9. Testes antigos ajustados por mudança intencional do portão (Dashboard 11→4 bloqueadas; Estoque/OperacoesProvisorias passam a exigir bloqueio só da TRANSFERÊNCIA; Referências 6 listas).
  - `tsc -b` limpo; Vitest **25/25** (novo: devolução parcial); build OK.
  - **E2E 28 passed / 0 failed / 11 skipped.**
  - Backup/restauração **19/19** (pré-condição: banco E2E com documentos; sem isso o caso de controle falha). Auditoria de dependências: 0 vulnerabilidades.
  - Implantação R1 `50ff2d8` → R2 `2f3e2a2`: **29/29** (o caso do manifesto da R2 agora exige a lista exata das operações decididas).
  - Migração em banco populado (seed no schema do main → 7 migrações CP-12+CP-13): IDs e contagens idênticos, cadeia íntegra, 5 níveis, ARMEIRO criado, `dados.importar` em 3 papéis. Rollback das 3 do CP-13 e depois das 4 do CP-12 sem perda; reaplicação OK. **Defeito achado e corrigido:** o `down()` da migração do Armeiro usava `whereKey` em query builder e não era atômico.

## CP-12 — Melhorias estruturais do MVP (branch `feat/cp12-melhorias-estruturais-mvp`, base main `50ff2d8`) — TESTED, não mesclado
- Escopo: `docs/desenvolvimento/CP-12-MELHORIAS-ESTRUTURAIS.md`. 4 migrações aditivas `2026_11_10_000100..000400` (tipos de material com perfil de campos e tipo "Geral" por categoria; atributos do item individual/colete com CHECK de datas; dados funcionais do militar; `militar_unidades` com coluna gerada UNIQUE, CHECK e triggers sem DELETE/reabertura).
- Conservação unificada (`PATCH /stock/individual-items/{id}/conservation`, justificativa, auditada, não muda situação), alertas informativos ITEM_VENCIDO/ITEM_VENCENDO, consulta de material vinculado (`GET /stock/linked`, RF-VIN-06), vínculos secundários que não ampliam escopo. Telas Itens/validades e Material com efetivos.
- Limites respeitados: sem CPF (P-09), sem devolução parcial (P-05), sem transição direta de situação nem bloqueio por validade (P-03), portão `SIGARB_OPERACOES_PROVISORIAS` mantido, nenhuma migração antiga editada, triggers de auditoria intactos.
- Validação no Linux (2026-11-07/08), implementado no Windows sem testes:
  - Pint `--test`: 164 arquivos OK. PHPUnit: **155/155 (1312 asserções)**, inclui `Cp12EstruturalTest` 8/8. Correção: `ReferenciaTest` esperava 4 listas; agora 5 com asserção explícita de `tipos-material`.
  - Front: `tsc -b` limpo; Vitest **24/24**; build OK.
  - Backup/restauração **19/19** (1ª execução falhou porque o banco E2E fora populado pelo checkout do main e os documentos estavam no storage de outro diretório; repopulado pelo worktree).
  - Auditoria de dependências: 0 vulnerabilidades (Composer e npm, prod e dev).
  - Implantação (R1 `50ff2d8` → R2 `36f831d`): **29/29**.
  - Migração em banco populado (seed no schema do main → CP-12): contagens, IDs máximos e hash das listas de IDs idênticos em 7 tabelas; 3 tipos "Geral"; 4/4 materiais com tipo, 0 sem tipo; cadeia de auditoria íntegra. Rollback `--step=4` removeu tudo com dados e cadeia íntegros; reaplicação OK.
  - **E2E: NÃO verde.** Suíte completa 23 ok / 5 falhas / 11 puladas. Reexecução isolada dos 3 specs: branch 5/6, **main `50ff2d8` 2/6** nos mesmos specs. Falhas preexistentes de ambiente (disco NTFS/FUSE lento; telas presas no login após logins sequenciais), não regressão do CP-12; pendente de investigação.
- Ambiente: worktree criado no Windows (`git worktree repair`); 155 arquivos convertidos de CRLF para LF.

## CP-11 — Ajustes de interface pedidos pelo responsável (main local)
- Login no padrão das telas de acesso do RondaSocial/Visita-Segura (fundo da marca, cartão translúcido, mostrar senha). Sem logo de órgão: o SRS não define o órgão responsável.
- Barra lateral no padrão do RondaSocial (largura animada, item ativo em branco, títulos que desdobram, perfil e "Sair" no rodapé).
- Perfil do efetivo com moldura de foto (upload sem metadados, servido só com sessão/escopo), dados funcionais, conta, armamento acautelado e entregas.
- Ações de cadastro: "Cadastrar unidade", catálogo para o Administrador, atalhos para cadastrar material/efetivo dentro dos formulários.
- Interface sem rótulos de demonstração/provisório; dados de exemplo com aparência de produção (sintéticos). As regras provisórias continuam marcadas no servidor e na auditoria.
- Gráficos do painel interativos (Chart.js 4.4.7): dica com valor e %, clique abre a lista filtrada; tabela e atalhos por teclado como alternativa.

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
