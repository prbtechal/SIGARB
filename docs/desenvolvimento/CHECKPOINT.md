# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-06T02:45:05-03:00
- **Branch:** `feat/checkpoint-7-observabilidade-recuperacao` (criado de main `2254704`). **Não mesclado.**
- **HEAD validado:** c41d091 (este arquivo é commitado logo depois)
- **main local:** `2254704` = merge do CP-6 (`b3c0f81`) + registro da auditoria de publicação.
- **Fase atual:** CHECKPOINT 7 — **concluído no escopo local**.
- **Status geral:** MVP local de demonstração. **Não aprovado para produção. Nada implantado.**

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
