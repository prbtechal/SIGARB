# PE-07 — Âncora independente da cadeia de auditoria (2026-11-06)

Execução sobre **cópia descartável** (banco `sigarb_proto_pe07`, removido ao final) de 53 eventos sintéticos do ambiente E2E.
Âncora = `{ultimo_id, ultimo_hash, total, emitida_em}` assinada com Ed25519 (libsodium, nativa do PHP); chave privada fora do banco.

| Situação | Verificação local (atual) | Âncora assinada |
|---|---|---|
| Cadeia original | íntegra | confere |
| DBA altera evento e **recalcula toda a cadeia e o ponteiro** | **íntegra (não detecta)** | **diverge — detectado** |
| Atacante forja a âncora sem a chave privada | — | assinatura inválida — detectado |

Script: `prototipos/pe07/prova.php` (comandos em `docs/seguranca/AUDITORIA.md`).

## Opções comparadas
| Opção | Protege contra DBA que reescreve a cadeia? | Custo | Limitação |
|---|---|---|---|
| Âncora assinada periódica (protótipo) | Sim, para eventos anteriores à última âncora | Baixo (cron + armazenamento externo) | Janela entre âncoras; a chave e as âncoras precisam estar fora do alcance do DBA |
| HMAC por evento com chave fora do banco | Sim, se a chave não estiver no servidor de banco | Baixo/médio (troca do algoritmo; migração da cadeia) | Quem controla o servidor de aplicação + banco ainda pode reescrever |
| Log externo WORM (syslog central/armazenamento imutável) | Sim | Depende da infraestrutura | Exige serviço institucional existente |

## Decisões institucionais necessárias (PE-07)
- Onde guardar a chave de assinatura (HSM, cofre, servidor distinto) e quem a administra.
- Destino das âncoras (equipe de segurança, syslog central, mídia WORM) e periodicidade (ex.: a cada hora e no fim do dia).
- Quem executa a verificação e como incidentes são tratados.
Nada disso foi ativado na aplicação.
