# Modelo de domínio (MVP)

| Domínio | Entidades implementadas | Planejadas (bloqueadas) |
|---|---|---|
| Acesso | usuarios, sessions, dispositivos_confiaveis, papeis, permissoes, papel_permissao, atribuicoes_papel | política de senha/MFA (P-11) |
| Pessoas | militares, postos_graduacoes | situações funcionais oficiais |
| Organização | organizacoes, unidades (árvore, `caminho`), locais_estoque (RESERVA/DEPOSITO) | hierarquia real (P-02) |
| Catálogo | categorias (natureza), fabricantes, calibres, materiais (`modo_controle`) | modelos, tipos de documento |
| Estoque | lotes, itens_individuais, saldos_estoque | reservas, inventários, ajustes |
| Operações | movimentacoes (ENTRADA, ESTORNO), movimentacao_itens | transferências, entregas, devoluções, manutenção, baixa |
| Acautelamento | — | solicitações, aprovações, acautelamentos, inspeções, carteiras (P-06/P-07) |
| Documentos | — (só `documento_referencia` textual) | documentos, documento_vinculos (RF-014) |
| Governança | eventos_auditoria, auditoria_cadeia | alertas, configuracoes editáveis |

## Invariantes garantidas no banco
- `saldos_estoque.quantidade`, `quantidade_reservada` UNSIGNED e `CHECK (reservada <= quantidade)` → sem saldo negativo (RN-002).
- `movimentacao_itens`: exatamente um entre item individual e lote; quantidade > 0; individual = 1.
- `movimentacoes`, `movimentacao_itens`, `eventos_auditoria`: triggers impedem UPDATE/DELETE (RN-017, RNF-04).
- `movimentacoes.estorno_de_id` UNIQUE (um estorno por movimentação) e `chave_idempotencia` UNIQUE.
- `itens_individuais.identificador` UNIQUE; `(material_id, numero_serie)` UNIQUE (RN-003). `(material_id, codigo)` de lote UNIQUE.
- `usuarios.militar_id` UNIQUE (vínculo 0..1:1, §2.1).

## Estados do item individual
`DISPONIVEL` (após entrada) → `ESTORNADO` (estorno). Demais (`RESERVADO`, `ACAUTELADO`, `MANUTENCAO`, `BAIXADO`) existem no esquema, mas nenhuma operação habilitada os produz.
