# Central de Suporte — controles de segurança (CP-9)

| Ameaça | Controle | Evidência |
|---|---|---|
| Ver chamado alheio / enumerar | Rotas por ULID; solicitante só os próprios; atendimento só no escopo de `suporte.atender`; 404 uniforme para inexistente e alheio | `SuporteTest::test_isolamento_*`, `test_atendimento_restrito_*` |
| Escalada via permissão de suporte | `suporte.atender` separado; não concede `estoque.ver`/`documentos.ver` | `test_permissao_de_suporte_nao_concede_dados_organizacionais` |
| Vazamento de nota interna | Filtrada na consulta para não atendentes; solicitante não cria INTERNA (403) | `test_nota_interna_*`, e2e `suporte.spec.ts` |
| Segredos/dados pessoais colados no texto | Redação antes de gravar (senha/token/Bearer/JWT/base64/chave privada/CPF), marca `redigido`; falha do PCRE = texto descartado; auditoria nunca guarda texto livre | `test_redacao_*` |
| Diagnóstico com payload sensível | Só rota sem query (regex no servidor), navegador truncado, request_id e versão; nunca corpo/cabeçalhos | `test_diagnostico_seguro_*` |
| Alterações concorrentes | Versão otimista + `FOR UPDATE`; 409 `CONFLITO_VERSAO`; UI bloqueia "Salvar" com envio/recarga em andamento | `ConcorrenciaTest::test_alteracoes_simultaneas_*` (8 processos: 1 vence) |
| Adulteração do histórico | Mensagens append-only e identidade do chamado imutável (triggers 45000); chamados não são apagados | `test_mensagens_e_chamados_sao_imutaveis_no_banco` |
| Rastreabilidade | Toda ação gera evento na cadeia de auditoria **na mesma transação** (fail-closed); histórico por chamado para o atendimento; filtro por `request_id` | `test_historico_*`, cadeia verificada |
| Abuso/spam | Throttle: abrir 5/min, mensagem 20/min, reabrir 10/min, atualizar 60/min | `test_limite_de_taxa_na_abertura` |
| Notificação alheia | Consulta filtrada pelo dono; id alheio = 404; texto sem conteúdo do chamado | `test_notificacoes_isoladas_por_usuario` |
| Upload malicioso | Anexos **não** implementados nesta versão | — |

Pendente institucional: quem atende (equipe e escopo), SLA e retenção dos chamados (P-08/P-10).
