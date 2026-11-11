# Histórico de checkpoints (somente acréscimos)

## CP-0 — Inicialização (2026-11-05)
SRS localizado (1 arquivo, SHA-256 00d37d32…4c5c), copiado sem alteração; DOCX mantido fora do Git (PE-03). Extrato, pendências e ADRs criados. SIGARB não existia; nenhum trabalho alheio foi tocado.

## CP-1 — Fundação + cadastros + estoque (2026-11-05, 2990cfd)
Laravel 13/PHP 8.5/MySQL 8.4 em Docker isolado. 53 testes verdes. Bug real achado pelos testes: o MySQL reordena as chaves JSON e o cast datetime descartava microssegundos; ambos quebravam a cadeia de hash da auditoria e foram corrigidos antes do commit.

## CP-2 — Endurecimento de login (2026-11-05, 14940ff → cc071ce)
A revisão automática de segurança apontou: oráculo de matrícula na busca, força bruta distribuída, bloqueio de conta por terceiros, divergência de normalização e cookie de dispositivo não revogável. Todos corrigidos em iterações com testes de regressão.
**Falha de processo:** o commit 2e089f6 entrou com 1 teste falhando (cadeia `&&` com grep); corrigido em f52b782. Regra adicionada ao AGENTS.md.

## CP-3 — Front-end + E2E (2026-11-05, c37341e)
SPA React com os fluxos do MVP; referências de UX no Mobbin registradas. O E2E revelou que `php artisan serve` não repassava DB_* do container; substituído por `php -S` + server.php. Cliente HTTP passou a rejeitar respostas não-JSON.

## CP-4 — Documentação e MVP pronto para demonstração interna (2026-11-05T21:09:55-03:00, f817a64)
Matriz gerada (32 TESTED, 13 IN_PROGRESS, 3 IMPLEMENTED, 27 BLOCKED, 10 NOT_STARTED, 0 ACCEPTED). Pipeline completo verde: 60 backend, 13 Vitest, 12 E2E. Próximo passo: RF-014 documentos.

## CP-5 — Gestão segura de documentos RF-014 (2026-11-06T00:01:16-03:00, 662dc1f, branch feat/documentos-rf014)
Estado inicial conferido: HEAD f0ec846 = CP-4, árvore limpa, sem remoto. Módulo de documentos com disco privado, lista de permissão com verificação de conteúdo, download só como anexo com checksum, 404 fora do escopo, triggers de imutabilidade, telas e E2E.
A revisão automática de segurança achou 3 fragilidades na validação de PDF (escape #xx, /ObjStm, regex falhando aberta), todas corrigidas com teste. Os testes revelaram um defeito latente: a cadeia de auditoria recomeçava em silêncio sem o ponteiro; agora falha fechada.
Limitação registrada: reescrita total da cadeia por DBA não é detectada localmente (proposta PE-07).
Incidente de ambiente: queda e remontagem do disco FUSE e baixa memória causaram falhas transitórias de Vitest/E2E; reexecutado em ambiente estável. O E2E passou a servir o build de produção.
Resultado: 89 backend, 20 Vitest, 14 E2E, todos verdes. RF-014 TESTED (não ACCEPTED).

## CP-5 integrado ao main (2026-11-06T00:20:56-03:00)
- Pré-merge: HEAD dae20ae conferido, árvore limpa, SRS SHA-256 inalterado, sem segredos versionados, main sem divergência.
- Merge `--no-ff` → `ea2b0bc`. **A suíte sobre o main falhou** (1/89): deadlock em 8 entradas simultâneas no mesmo lote esgotou 3 tentativas e recusou uma entrada legítima (sem perda de integridade). Defeito pré-existente e intermitente, não introduzido pelo CP-5.
- Correção em `fix/deadlock-entrada-concorrente` (linhas de lote/saldo criadas antes da transação; 5 tentativas; regressão 3×10 entradas). Concorrência verde em 3 execuções seguidas.
- Merge da correção → **main `d81745d`**: 90 backend (651 asserções), 20 Vitest, build, 14 E2E + 3 skips. Branches `feat/documentos-rf014` e `fix/deadlock-entrada-concorrente` preservados.

## CP-6 — Administração, painel e endurecimento (2026-11-06T01:41:36-03:00, e3f2789, branch feat/checkpoint-6-admin-dashboard-hardening)
RF-029 e RF-024 TESTED. Três defeitos de segurança/disponibilidade encontrados e corrigidos com teste: bypass de limite por IP via Host falsificado (provado vermelho antes da correção), rota irmã de catálogo sem a permissão global, deadlock em entradas concorrentes. CSP estrita validada com sonda negativa; runtime MySQL sem DDL validado com E2E completo; 0 vulnerabilidades de dependências.
Protótipos: Ghostscript se mostrou **ineficaz** como sanitizador (preserva JS); qpdf + varredura eficaz; âncora Ed25519 detecta reescrita total da cadeia.
Navegadores: Chromium, Firefox, WebKit. Axe desativado só no Firefox 159 após investigação (crash da ferramenta, não da aplicação).
Falha de processo registrada: `scripts/test.sh` terminou com exit 127 numa execução (caminho relativo da auditoria); corrigido e reexecutado com exit 0.

## CP-6 integrado ao main e auditoria de publicação (2026-11-06T02:00:22-03:00)
- Revisão pré-merge: sem dependências novas; única permissão nova `referencias.gerir` (ADMIN global); portão com 2 operações habilitadas (ENTRADA, ESTORNO); nenhum teste removido (3 mudanças só de assinatura); regressões de segurança presentes.
- Merge `--no-ff` → **main `b3c0f81`**. Sobre main: `scripts/test.sh` exit 0 (105 backend, 24 Vitest, build, 16 E2E + 3 skips, 0 vulnerabilidades); `scripts/verificar-privilegios.sh` exit 0 (10/10 negações, E2E com runtime sem DDL 14 passed).
- GitHub `prbtechal/SIGARB`: **privado**, **vazio** (sem branches/tags), sem rulesets disponíveis no plano; acesso: prbtechal (admin), joseook (write).
- Auditoria do histórico publicável (29 commits, 255 caminhos): DOCX do SRS nunca versionado; 0 ocorrências dos 6 segredos reais locais; 0 chaves privadas/APP_KEY/tokens; sem dumps, logs, builds, uploads. Aviso: IPs institucionais 192.168.200.8/.9 constam da documentação — aceitável apenas por o repositório ser privado.
- **Push NÃO executado:** bloqueado pela política de permissões da sessão do agente. Remoto não configurado localmente. Aguarda execução/autorização do usuário.

## CP-7 — Observabilidade e recuperação (2026-11-06T02:45:05-03:00, c41d091, branch feat/checkpoint-7-observabilidade-recuperacao)
Logs JSON correlacionados, liveness/readiness, backup/restauração local cifrados com 11 casos, carga local com k6 (0% erro, integridade preservada), verificações de acessibilidade sem axe nos três motores. Firefox: axe.run derruba o navegador com qualquer injeção — compensado. Primeira rodada de carga (83% de erro) era defeito do script e foi descartada. Push ao GitHub continua pendente (bloqueado pela política de permissões do agente); auditoria de publicação aprovada.

## CP-7 integrado ao main e publicado (2026-11-06)
- Merge local do CP-7 em main: `ff0eb04`. Push normal (sem force) `2254704..ff0eb04` para `origin/main` (repositório privado). Somente main publicado.

## CP-8 — Runtime production-like e capacidade (2026-11-06, branch feat/checkpoint-8-runtime-capacity-validation)
- Ambiente local Apache 2.4 event + PHP-FPM 8.5 (OPcache) + MySQL 8.4, usuários de banco separados (app/migração), dados sintéticos (`fc5dfd2`).
- **Incidente de segurança (local):** uma execução `bash -x scripts/prodlocal.sh` imprimiu na sessão do agente segredos de DEV (valores do `.env` do prodlocal e senha de demonstração). Mitigação imediata: ambiente prodlocal destruído e `.env` regenerado; `SIGARB_DEMO_PASSWORD`, `SIGARB_DB_APP_PASSWORD` e `SIGARB_DB_MIGR_PASSWORD` rotacionados sem impressão; banco de demonstração recriado. Nenhum segredo foi versionado nem publicado. Regra: **nunca** usar `bash -x` em scripts que carregam `.env`.
- Investigação de gargalo (evidência em `docs/operacao/CAPACIDADE.md`): causa raiz `enablereuse=on` (504 a 35 s; 1,21%/1,92%/5,61% de falhas a 10/25/50 VUs — relato inicial de "0%" corrigido); rajada de login sem rampa no harness; espera dupla de lock em `INSERT IGNORE` de saldo. Corrigidos. 50 VUs: 0% de erro em 2 repetições, integridade íntegra. 100 VUs não executado (host compartilhado no limite).
- Defeitos corrigidos: sondas de saúde retornavam 500 com banco fora (dependiam de sessão/cache em banco); harness de carga deixava coletor órfão e apagava evidência; auditoria de dependências relatava falha de rede como "vulnerabilidade".
- Validação final (2026-11-06): merge **NO-GO** — `composer audit` impossível (packagist.org inacessível da rede local). Testes 112/24/19/19 verdes, npm 0. Teste de concorrência em lote novo reforçado (1 linha de saldo por lote, sem atualização perdida). Pint: ~60 arquivos legados divergentes descobertos (não é gate). Documento de prontidão para homologação criado; nenhum acesso a VMs.
- Auditoria concluída (rede do host; DNS rotativo do packagist com IPs inalcançáveis): 0 advisories; pipeline exit 0 → merge GO.
- CP-8 integrado: merge `d3ea7dd`, pipeline exit 0 no main, push `ff0eb04..d3ea7dd`. Pint: branch `chore/php-pint-standardization` validado, não mesclado.
- Release/piloto (2026-11-06): pacote reprodutível + implantação versionada testada localmente 26/26; 4 defeitos de implantação corrigidos; piloto na VM NO-GO (infra). Branch feat/release-piloto não mesclado.
- CP-9 (2026-11-06): interface institucional (referência Sistema-de-escalas @849b07c) e Central de Suporte com auditoria integrada; branch feat/interface-institucional-central-suporte, não mesclado.
- CP-10 (2026-11-06/07): fluxos operacionais com regra provisória desligada por padrão (estoque, acautelamento, carteira, inventário, alertas, relatórios CSV, ficha técnica); branch feat/fluxos-operacionais-provisorios.
- CP-12 (2026-11-07/08): melhorias estruturais do MVP (tipos de material, colete, conservação, dados do militar, vínculos secundários, alertas de validade, material vinculado); PHPUnit 155/155, Vitest 24/24, backup 19/19, implantação 29/29, migração e rollback validados em banco populado; E2E com falhas de ambiente preexistentes (main 2/6 × branch 5/6 nos specs afetados); branch feat/cp12-melhorias-estruturais-mvp, não mesclado.
- CP-13 (2026-11-08): P-03/P-05/P-09 decididas pelo responsável (sem aceite institucional); papel Armeiro; devolução parcial; níveis de proteção administráveis; importação/exportação CSV de 8 cadastros. PHPUnit 164/164, Vitest 25/25, E2E 28/0, backup 19/19, implantação 29/29, migração e rollback validados; branch feat/cp13-armeiro-importacao-politicas, não mesclado.
- CP-14 (2026-11-08): P-02/P-06/P-07 decididas pelo responsável; todas as operações habilitadas; Armeiro com baixa, saída, transferência e aprovações; delegação com prazo; validade da carteira; importação de unidades e locais. PHPUnit 170/170, Vitest 25/25, E2E 28/0, backup 19/19, implantação 29/29, migração e rollback validados; branch feat/cp14-politicas-finais-armeiro, não mesclado.

## CP-15 — 2026-11-08 — Autoaprovação do Armeiro com auditoria completa
- PRs #4, #5 e #6 mesclados na `main` (merge commits, nessa ordem).
- Armeiro, Gestor e Aprovador podem pedir, aprovar e entregar o próprio acautelamento e aprovar o próprio ajuste (`SIGARB_AUTOAPROVACAO_PAPEIS`, padrão `ARMEIRO,GESTOR,APROVADOR`); risco aceito pelo responsável.
- Eventos de acautelamento na auditoria passam a gravar operador, solicitante, aprovador, autoaprovação, efetivo, material, item, quantidade e horário.
- Evidência: PHPUnit 170/170, Vitest 25/25.

## CP-16 — 2026-11-09 — Configurações do sistema pela interface
- PRs #10 e #11 mesclados (login e menu lateral no padrão do ecossistema).
- Nova tela "Configurações do sistema" (`/configuracoes`, permissão global `sistema.configurar`, só Administrador): liga/desliga cada operação, papéis com autoaprovação, bloqueio de vencido, prazos de alerta e inspeção, limite de sessões.
- O arquivo `config/sigarb.php` continua sendo o padrão; a tabela `configuracoes` guarda só o que mudou (valor NULL = voltar ao padrão). Desligar só bloqueia (ADR-006); não libera operação bloqueada no arquivo.
- Toda mudança exige motivo e gera `configuracao.alterada` na auditoria (antes, depois, motivo). Limites de login, senha e upload ficam fora de propósito.
- Evidência: PHPUnit 174/174 (novo `ConfiguracoesSistemaTest`), Vitest 25/25, tsc e build OK, tela conferida em 1280px e 360px.

## 2026-11-10 — Tour guiado por tela
- Componente `Tour` (sem dependência nova): na primeira visita de cada usuário a uma tela com tour, abre um balão que destaca partes da tela ("1 de N", Voltar/Próximo/Pular, Esc fecha). Botão "Tour" no topo permite rever.
- Passos definidos por rota em `frontend/src/components/Tour.tsx` (`TOURS`); passo cujo alvo não aparece (perfil sem o botão, celular) é pulado.
- "Já visto" fica no navegador (localStorage, por usuário e por tela).
- Referências Mobbin: Canva, beehiiv, Front, Miro (balão ancorado com contador e fundo escurecido). Descartado: vídeo de boas-vindas e checklist de primeiros passos.
- Evidência: Vitest 27/27 (novo `Tour.test.tsx`), tsc e build OK, conferido em 1280px e 360px.

## 2026-11-10 — Tour guiado também em formulários e diálogos
- `Tour` passou a ter roteiros de tela (`rota`) e de contexto (`quando`): ao abrir um diálogo/formulário (criar conta, papéis, cadastrar material/local/unidade, abrir inventário, confirmar entrada/operação, estorno, conservação, devolução, etapas da cautela, ajustes, referências, vínculo de documento) aparecem dicas dos campos que têm regra.
- Âncoras estáveis: `Campo` ganhou `data-tour` (rótulo sem acento) e `Dialogo` `data-tour` (título, ou prop `tour` quando o título é dinâmico).
- O balão é renderizado dentro do `<dialog>` aberto (fora dele a página fica inerte); Esc fecha só o balão; botão "?" no diálogo para rever.
- Para não cansar: até 4 passos por roteiro, cada um uma vez por usuário, passo sem alvo visível é pulado, e "Não mostrar dicas automáticas" desliga tudo (o botão "Tour" continua).
- Evidência: Vitest 29/29 (3 testes do tour), tsc e build OK; diálogo "Criar conta" conferido em 1280px e 360px.

## 2026-11-11 — Edição de conta, estrutura PRB, tipo de efetivo e marca "Arsenal"
- Administrador edita nome, login, e-mail, efetivo vinculado e senha de qualquer conta (`PUT /users/{id}`, login agora editável com unicidade; troca de senha encerra sessões). Tela: botão "Editar" em Usuários.
- Migração `2026_11_17_000200_estrutura_prb`: organização PRB e unidades Perímetro → Base → Unidade iguais às do Sistema de Escalas (Maceió › Poço › Orla/Centro/Jacintinho, Maceió › Benedito, Marechal Deodoro › Francês, Arapiraca, Paripueira, Rio Largo). Só estrutura, nenhuma pessoa; locais de estoque continuam cadastrados pela tela.
- Militar ganhou `categoria_efetivo` (RR, R1, VIGILANTE, PM_ATIVA, BM_ATIVA). Armamento e munição só para RR e PM/BM da ativa: barrado no pedido e na entrega da cautela e na operação de entrega (`EFETIVO_NAO_APTO_LETAL`). Não informado = não recebe material letal.
- Ícone da aba = logo do Ronda no Bairro; barra lateral mostra "Arsenal".
- Evidência: PHPUnit 183/183, Vitest 29/29, tsc e build OK, telas conferidas.

## 2026-11-11 — Permissões pela tela, tipo de efetivo só pelo admin, login por CPF e senha padrão
- Tela **Permissões** (`/permissoes`, `sistema.configurar`): matriz papéis × permissões; salva em lote com motivo; `permissao.alterada` na auditoria; ADMIN não perde `sistema.configurar`/`usuarios.*` (trava). `GET /permissions`, `PUT /roles/{papel}/permissions/{permissao}`. Runtime ganhou DELETE só em `papel_permissao`. Seeder não sobrescreve mais a matriz editada (só preenche papel novo/vazio e permissão nova).
- Tipo de efetivo saiu do cadastro comum: `PUT /militares/{id}/categoria` com `militares.classificar` (só ADMIN), motivo obrigatório, `militar.categoria_alterada`.
- Login pelo CPF (11 dígitos, DV validado; máscara aceita). Contas antigas continuam entrando. Conta nova/redefinida recebe `SIGARB_SENHA_PADRAO` (padrão `@Prb#123!`) e `senha_provisoria=1`: middleware `SenhaProvisoria` bloqueia tudo (403) exceto `/me`, `/me/password` e logout até a troca. Senha mínima 8 (P-11 revisada).
- Evidência: PHPUnit 186/186, Vitest 29/29, tsc e build OK; telas de troca de senha e permissões conferidas.

## 2026-11-11 — Carga do inventário de material bélico 2026 (branch `feat/importar-inventario-smb-2026`)
- Migração `2026_11_18_000100_remover_secoes_poco_sem_uso`: remove 9 seções do Poço sem referência; seção com referência é só desativada.
- Comando `sigarb:inventario:importar` com `--simular` e `--usuario`: catálogo, unidades COPOM/GUARDA-BASE sob o Poço (decisão do responsável), 11 locais e estoque via `EstoqueService`, de forma idempotente e auditada. Documento de referência: "Inventário Material Bélico 2026 – Portaria 01/2023/SPRB".
- A planilha real fica fora do git; os testes usam dados sintéticos.
- Evidência: PHPUnit 191/191. Carga real em banco descartável: 591 linhas, 0 erros, reexecução sem duplicar, auditoria íntegra.

## 2026-11-11 — Adequação à PSI-PRB v1.0 (branch `feat/psi-prb-v1`)
- Senha com regra da PSI (mínimo 8 por exceção E-01), histórico de 5, expiração em 90 dias; bloqueio após 10 falhas com desbloqueio pelo gestor; MFA TOTP opcional (E-02); desativação de contas inativas há 60 dias; rótulo "INFORMAÇÃO RESTRITA – PSI-PRB" nas exportações; logs por 180 dias.
- Senha inicial/redefinida = CPF para conta não privilegiada (E-03); risco R-01 aceito pelo responsável.
- Dados funcionais da conta e tela "Meu perfil".
- Evidência: PHPUnit 211/211, Vitest 34/34, tsc e build OK. Documento: `docs/seguranca/PSI-PRB-CONFORMIDADE.md`.

## 2026-11-11 — Auditoria legível e detalhe do material no estoque (branch `feat/auditoria-legivel-saldos`)
- Auditoria em frases ("<quem> <verbo>: <alvo>") com rótulos em português para todos os códigos de ação, alvo legível no escopo de quem consulta (CPF mascarado; sem acesso, "Tipo #id"), painel Quando/Quem/O quê/Dados e JSON técnico recolhido. Só apresentação: banco e cadeia de hash intactos.
- Detalhe do material no estoque (`/estoque/materiais/:id`, `GET /stock/materials/{id}`), filtro `material_id` nas movimentações, `/saldos` → `/estoque`. Saldo só muda pelos fluxos com motivo.
- Pesquisa Mobbin registrada em `UX-REFERENCIAS.md` (Discord, Railway, Confluence; Deel, Shopify).
- Evidência: PHPUnit 202/202, Vitest 35/35, tsc e build OK.
