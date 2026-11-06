# Critérios de aceite do MVP (proposta para validação)

| # | Critério | Verificação | Situação |
|---|---|---|---|
| CA-01 | Login com sucesso/falha/logout auditados; senha nunca registrada | AuthTest, e2e autenticacao | Atendido (teste) |
| CA-02 | Sessões revogáveis; desativação corta acesso imediatamente | AuthTest | Atendido (teste) |
| CA-03 | Nenhuma ação sem permissão e escopo no servidor (horizontal e vertical) | Org/Militar/Estoque tests, e2e escopo | Atendido (teste) |
| CA-04 | Militar existe sem conta; vínculo único e opcional | MilitarTest | Atendido (teste) |
| CA-05 | Catálogo distingue individual × lote; modo imutável após estoque | CatalogoTest | Atendido (teste) |
| CA-06 | Entrada idempotente, transacional, sem duplicar identificador | EstoqueTest, ConcorrenciaTest | Atendido (teste) |
| CA-07 | Nenhum saldo negativo sob concorrência | ConcorrenciaTest, EstoqueTest (banco rejeita) | Atendido (teste) |
| CA-08 | Correção apenas por estorno; razão e auditoria imutáveis | EstoqueTest, AuditoriaTest | Atendido (teste) |
| CA-09 | Operações sem política não produzem registro nem documento | OrganizacaoEPermissoesTest, e2e | Atendido (teste) |
| CA-10 | Interface responsiva sem violações sérias/críticas no axe | e2e responsivo-acessibilidade | Atendido (automático); manual pendente |
| CA-11 | Ambiente reproduzível com dados fictícios | scripts/dev.sh, reset-demo.sh | Atendido |

Aceite institucional: **nenhum critério aceito formalmente** (requer representantes autorizados, SRS §13).
