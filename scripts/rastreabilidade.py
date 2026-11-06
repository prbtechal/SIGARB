#!/usr/bin/env python3
"""Gera docs/requisitos/MATRIZ-RASTREABILIDADE.md.
Definições: lidas do extrato fiel do SRS (SRS-EXTRAIDO.md). Mapeamento/status: dicionário M abaixo (manter atualizado).
Uso: python3 -I scripts/rastreabilidade.py"""
import re, pathlib, sys
RAIZ = pathlib.Path(__file__).resolve().parent.parent
srs = (RAIZ / 'docs/requisitos/SRS-EXTRAIDO.md').read_text(encoding='utf-8')

def linhas(prefixo):
    out = {}
    for l in srs.splitlines():
        m = re.match(r'\| (%s[-\d]+) \| (.*) \|$' % prefixo, l)
        if m:
            out[m.group(1)] = [c.strip() for c in m.group(2).split(' | ')]
    return out

RF, RNF, RN, UC, P = linhas('RF'), linhas('RNF'), linhas('RN'), linhas('UC'), linhas('P')
B = 'backend/app/'
T = {  # atalhos para testes
    'auth': 'Feature/AuthTest', 'org': 'Feature/OrganizacaoEPermissoesTest', 'mil': 'Feature/MilitarTest', 'cat': 'Feature/CatalogoTest',
    'est': 'Feature/EstoqueTest', 'aud': 'Feature/AuditoriaTest', 'conc': 'Feature/ConcorrenciaTest',
    'e2e-auth': 'e2e/autenticacao.spec', 'e2e-est': 'e2e/estoque.spec', 'e2e-a11y': 'e2e/responsivo-acessibilidade.spec', 'vt': 'vitest src/**',
    'ref': 'Feature/ReferenciaTest', 'dash': 'Feature/DashboardTest', 'proxy': 'Feature/ProxyConfiavelTest', 'e2e-csp': 'e2e/csp.spec',
    'priv': 'scripts/verificar-privilegios.sh', 'nav': 'scripts/e2e-navegadores.sh (Chromium/Firefox/WebKit)',
    'obs': 'Feature/ObservabilidadeTest', 'bkp': 'scripts/testar-backup.sh', 'carga': 'scripts/teste-carga.sh (k6)', 'a11y-est': 'e2e/acessibilidade-estrutura.spec',
    'doc': 'Feature/DocumentoTest', 'adult': 'Feature/AuditoriaAdulteracaoTest', 'e2e-doc': 'e2e/documentos.spec', 'vt-doc': 'vitest documentos/arquivos',
}
def t(*k): return ', '.join(T[x] for x in k)
BLOQ = lambda p: dict(st='BLOCKED', notas=f'Operação bloqueada (409 OPERACAO_BLOQUEADA) até {p}. Esquema preparado quando indicado.', testes=t('org', 'est', 'e2e-est'))
# id: (rn, uc, backend, frontend, api, entidades, testes, status, notas)
M = {
 'RF-001': dict(rn='RN-020', uc='UC-01', be='AuthController@login/logout', fe='LoginPage', api='POST /auth/login, /auth/logout', ent='usuarios, sessions, eventos_auditoria', testes=t('auth','e2e-auth'), st='TESTED', notas='Rate limit conta+IP, conta (anti-distribuído) e IP; dispositivo confiável no servidor. Política final: P-11.'),
 'RF-002': dict(rn='RN-020', uc='UC-01', be='AuthController@sessoes/revogarSessao, UsuarioController@revogarSessoes', fe='SessoesPage', api='GET/DELETE /me/sessions, POST /users/{id}/sessions/revoke', ent='sessions, dispositivos_confiaveis', testes=t('auth'), st='TESTED', notas='Limite provisório 3 sessões (P-11).'),
 'RF-003': dict(rn='RN-019, RN-024', uc='todos', be='AutorizacaoService, Policies/*', fe='Exige/menu por permissão (apenas UX)', api='todas', ent='papeis, permissoes, atribuicoes_papel, unidades.caminho', testes=t('org','mil','est','e2e-est'), st='TESTED', notas='Escopo = subárvore da unidade (ADR-004); hierarquia real P-02.'),
 'RF-004': dict(rn='RN-025', uc='UC-15', be='UsuarioController', fe='UsuariosPage', api='GET/POST /users, GET/PUT /users/{id}, POST/DELETE /users/{id}/roles', ent='usuarios, atribuicoes_papel', testes=t('auth','org'), st='TESTED', notas='Contas nunca apagadas; desativação revoga sessões e dispositivos.'),
 'RF-005': dict(rn='—', uc='UC-03', be='MilitarController', fe='MilitaresPage, MilitarFormPage', api='GET/POST /militares, GET/PUT /militares/{id}', ent='militares, postos_graduacoes, usuarios.militar_id', testes=t('mil','e2e-est'), st='TESTED', notas='Separado de usuário (§2.1); vínculo 0..1:1; dados mínimos (P-09).'),
 'RF-006': dict(rn='RN-024', uc='—', be='OrganizacaoController', fe='OrganizacaoPage', api='/organizations, /units, /locations', ent='organizacoes, unidades, locais_estoque', testes=t('org'), st='TESTED', notas='Modelo genérico em árvore; hierarquia real P-02.'),
 'RF-007': dict(rn='RN-003', uc='UC-03', be='CatalogoController, EstoqueService', fe='MateriaisPage, NovaEntradaPage', api='/items, POST /movements', ent='materiais, itens_individuais, calibres, fabricantes', testes=t('cat','est'), st='IN_PROGRESS', notas='Identificação individual e série únicas testadas; ficha técnica detalhada de armamento ainda não modelada.'),
 'RF-008': dict(rn='RN-004', uc='UC-04', be='EstoqueService@obterLote', fe='NovaEntradaPage, SaldosPage', api='POST /movements, GET /stock/balances', ent='lotes, saldos_estoque', testes=t('est','conc'), st='TESTED', notas='Tipo via categoria/material; lote, fabricante, validade e saldo.'),
 'RF-009': dict(rn='RN-003', uc='UC-03', be='CatalogoController', fe='MateriaisPage', api='/items', ent='materiais (modo_controle), itens_individuais.estado_conservacao', testes=t('cat','est'), st='TESTED', notas='Unitário x quantitativo por material (P-04).'),
 'RF-010': dict(rn='RN-012, RN-015', uc='UC-02', be='EstoqueController@saldos', fe='SaldosPage, InicioPage', api='GET /stock/balances', ent='saldos_estoque, itens_individuais', testes=t('est','e2e-est'), st='TESTED', notas='Filtros por local, unidade, natureza, material, busca.'),
 'RF-011': dict(rn='RN-001, RN-005, RN-006, RN-007', uc='UC-04, UC-05', be='EstoqueController@registrar, EstoqueService', fe='NovaEntradaPage, MovimentacoesPage', api='POST /movements', ent='movimentacoes, movimentacao_itens', testes=t('est','conc','e2e-est'), st='IN_PROGRESS', notas='ENTRADA e ESTORNO habilitados; saída/entrega/devolução/reserva/baixa/acautelamento BLOQUEADOS (P-03/P-05/P-06).'),
 'RF-012': dict(rn='RN-013', uc='—', be='rota reservada', fe='OperacoesPage', api='POST /transfers (409)', ent='movimentacoes (tipo TRANSFERENCIA previsto)', **BLOQ('P-02/P-03')),
 'RF-013': dict(rn='RN-002', uc='UC-04', be='EstoqueService (FOR UPDATE, CHECK, UNIQUE, idempotência)', fe='Idempotency-Key no cliente', api='POST /movements, /movements/{id}/reversal', ent='saldos_estoque, movimentacoes', testes=t('est','conc'), st='TESTED', notas='Concorrência multiprocesso provada (6 débitos de 10 aceitos sobre saldo 100).'),
 'RF-014': dict(rn='RN-019, RN-020, RN-021, RN-025', uc='—', be='DocumentoController, DocumentoService, disco privado "documentos"', fe='DocumentosPage, DocumentoEnviarPage, DocumentoPage, seção na MovimentacaoPage', api='GET/POST /documents, GET/PUT /documents/{codigo}, GET …/download, POST …/archive|reactivate|links, GET /document-types', ent='documentos, documento_vinculos, tipos_documento', testes=t('doc','conc','adult','e2e-doc','vt-doc'), st='TESTED', notas='Guarda metadados/arquivos e vincula a movimentações. Emissão de documento oficial NÃO implementada. Classificação de sigilo (P-09) e retenção (P-08) pendentes; tipos de documento provisórios (PE-05).'),
 'RF-015': dict(rn='RN-009, RN-010', uc='UC-06, UC-08, UC-10', be='rotas reservadas', fe='OperacoesPage', api='POST /acautelamentos/* (409)', ent='planejadas', **BLOQ('P-06/P-07')),
 'RF-016': dict(rn='RN-009', uc='UC-07', be='rota reservada', fe='OperacoesPage', api='POST /acautelamentos/{id}/decision (409)', ent='aprovacoes (planejada)', **BLOQ('P-06')),
 'RF-017': dict(rn='RN-022', uc='UC-08', be='—', fe='—', api='—', ent='carteiras (planejada)', **BLOQ('P-07')),
 'RF-018': dict(rn='RN-010', uc='UC-09', be='rota reservada', fe='OperacoesPage', api='POST /acautelamentos/{id}/inspections (409)', ent='inspecoes (planejada)', **BLOQ('P-03')),
 'RF-019': dict(rn='RN-014, RN-015', uc='UC-11', be='—', fe='OperacoesPage', api='—', ent='itens_individuais.situacao MANUTENCAO', **BLOQ('P-03')),
 'RF-020': dict(rn='RN-016', uc='UC-12', be='—', fe='OperacoesPage', api='—', ent='planejadas', **BLOQ('P-03/P-06')),
 'RF-021': dict(rn='RN-012', uc='—', be='—', fe='OperacoesPage', api='—', ent='saldos_estoque.quantidade_reservada, situacao RESERVADO', **BLOQ('P-03')),
 'RF-022': dict(rn='RN-023', uc='UC-09', be='DashboardController (informativo)', fe='InicioPage', api='GET /alerts (409)', ent='alertas (planejada)', testes='—', st='NOT_STARTED', notas='Apenas indicador informativo de validade em 90 dias; regras configuradas pendentes.'),
 'RF-023': dict(rn='RN-019', uc='UC-13', be='—', fe='—', api='GET /reports/{key} (409)', ent='—', **BLOQ('P-12')),
 'RF-024': dict(rn='RN-024', uc='—', be='DashboardController (blocos por permissão, escopo, cache da verificação da cadeia)', fe='InicioPage, GraficoBarras', api='GET /dashboard', ent='—', testes=t('dash','vt','e2e-a11y','nav'), st='TESTED', notas='Indicadores e gráficos calculados no servidor por permissão/escopo; gráfico = tabela semântica (sem Chart.js, desvio reversível do §9.2); sem indicadores de operações bloqueadas.'),
 'RF-025': dict(rn='RN-020', uc='UC-14', be='AuditoriaService, AuditoriaController, bootstrap/app.php (negações)', fe='AuditoriaPage', api='GET /audit-events, /audit-events/integrity', ent='eventos_auditoria, auditoria_cadeia', testes=t('aud','adult','auth','e2e-auth','e2e-est','e2e-doc'), st='TESTED', notas='Append-only (triggers) e hash encadeado; matriz definitiva P-08.'),
 'RF-026': dict(rn='RN-024', uc='UC-02', be='ApiController@paginar, filtros por recurso', fe='filtros/paginação em todas as listas', api='?q, ?page, ?per_page≤100, ?sort (lista branca)', ent='—', testes=t('mil','est','cat'), st='TESTED', notas='Busca LIKE com curingas escapados; sem oráculo de matrícula.'),
 'RF-027': dict(rn='RN-017, RN-018', uc='—', be='EstoqueController@movimentacoes (item_individual_id/lote_id)', fe='MovimentacaoPage', api='GET /movements?item_individual_id=', ent='movimentacoes (imutável)', testes=t('est','aud'), st='TESTED', notas='Linha do tempo por item/lote; triggers impedem alteração.'),
 'RF-028': dict(rn='—', uc='—', be='—', fe='todas as telas', api='—', ent='—', testes=t('e2e-a11y','a11y-est','vt','nav'), st='IN_PROGRESS', notas='axe sem violações sérias/críticas em Chromium e WebKit (desktop/celular); teclado, foco de diálogo, zoom 200% nos 3 motores. Falta leitor de tela manual (roteiro em ACESSIBILIDADE.md).'),
 'RF-029': dict(rn='RN-023, RN-025', uc='—', be='ReferenciaController, ReferenciaService', fe='ReferenciasPage', api='GET /reference-lists, GET/POST /reference-lists/{tipo}, PUT …/{id}, POST …/{id}/deactivate|reactivate', ent='tipos_documento, categorias, fabricantes, calibres (ativo, desativado_em)', testes=t('ref','cat','vt'), st='TESTED', notas='Listas de referência administráveis (global, referencias.gerir), sem exclusão, desativação auditada, valor em uso não muda de significado. Regras configuráveis (alertas, inspeção) seguem em config/ e bloqueadas (P-03); listas oficiais pendentes (PE-05).'),
 'RF-030': dict(rn='—', uc='—', be='scripts/backup-local.sh, scripts/restaurar-local.sh, sigarb:documentos:verificar', fe='—', api='—', ent='todas (dump) + documentos referenciados', testes=t('bkp'), st='IN_PROGRESS', notas='Backup LOCAL cifrado (GnuPG AES-256) e restauração verificada em ambiente descartável, testados (11 casos). Agendamento automático, destino, retenção e RPO/RTO dependem de P-10/P-08.'),
}
MNF = {
 'RNF-01': ('IN_PROGRESS', 'Segredos fora do código; CSP de produção sem unsafe-*; proxies confiáveis explícitos (bypass por Host corrigido); identidade MySQL de runtime sem DDL. HTTPS/HSTS só na implantação (P-01).', t('proxy','e2e-csp','priv')),
 'RNF-02': ('IMPLEMENTED', "Cast 'hashed' (bcrypt); senha nunca auditada.", t('auth')),
 'RNF-03': ('TESTED', 'Gate/Policies em toda rota; listas filtradas na query.', t('org','mil','est','e2e-est')),
 'RNF-04': ('TESTED', 'Triggers append-only + cadeia de hash + verificação; falha fechada sem ponteiro. Limitação: reescrita total por DBA privilegiado não é detectada localmente (proposta de âncora externa em AUDITORIA.md).', t('aud','adult')),
 'RNF-05': ('IN_PROGRESS', 'Minimização de campos de militar; auditoria de documentos sem conteúdo; retenção e classificação pendentes (P-08/P-09).', t('mil','doc')),
 'RNF-06': ('TESTED', 'Transações, FOR UPDATE ordenado, CHECK, UNIQUE; deadlock em entradas concorrentes corrigido no CP-6 (linhas criadas fora da transação).', t('est','conc')),
 'RNF-07': ('IN_PROGRESS', 'CP-8 production-like local (Apache+FPM+MySQL, 2 CPU no app): 50 VUs 0% erro, ~47 req/s, p95 1,6 s em 2 repetições, integridade íntegra; gargalos medidos (CPU do app; cadeia de auditoria nas escritas) em docs/operacao/CAPACIDADE.md. 100 VUs não executado; metas não definidas (P-10); 1.000 usuários NÃO verificado.', t('carga')),
 'RNF-08': ('NOT_STARTED', 'API stateless exceto sessão em banco; filas/cache não configurados.', '—'),
 'RNF-09': ('IN_PROGRESS', 'Liveness/readiness (banco, disco privado, espaço) independentes de sessão/cache em banco (CP-8: banco fora → live 200/ready 503; disco baixo real → 503); backup/restauração local testados (19 casos). Monitoramento contínuo e SLA pendentes (P-10).', t('obs','bkp')),
 'RNF-10': ('TESTED', 'Gaveta no celular, tabelas em cartões, sem rolagem horizontal.', t('e2e-a11y')),
 'RNF-11': ('IN_PROGRESS', 'Verificação automática em 2 motores (axe) + teclado/foco/zoom em 3; manual com leitor de tela pendente.', t('e2e-a11y','nav')),
 'RNF-12': ('IN_PROGRESS', 'Fluxos validados em Chromium, Firefox e WebKit (desktop e celular); navegadores institucionais ainda não definidos.', t('nav')),
 'RNF-13': ('IMPLEMENTED', 'Monorepo, ADRs, testes automatizados, AGENTS.md, auditoria de dependências em scripts/test.sh.', 'scripts/auditar-dependencias.sh'),
 'RNF-14': ('TESTED', 'Logs JSON por requisição com request_id (correlacionado com resposta e auditoria), rota como modelo, sem corpo/query/cookies/senhas — testado. Retenção oficial de logs: P-08.', t('obs','aud')),
 'RNF-15': ('IN_PROGRESS', 'Restauração testada em ambiente isolado (automatizada); RPO/RTO e retenção não aprovados (P-10).', t('bkp')),
}
MRN = {
 'RN-001': ('TESTED', t('est')), 'RN-002': ('TESTED', t('est','conc')), 'RN-003': ('TESTED', t('est')), 'RN-004': ('TESTED', t('est')),
 'RN-005': ('TESTED', t('est') + ' (documento_referencia e origem obrigatórios; política PE-01)'), 'RN-006': ('BLOCKED', 'P-05'), 'RN-007': ('BLOCKED', 'P-05'),
 'RN-008': ('BLOCKED', 'P-05'), 'RN-009': ('BLOCKED', 'P-06 — garantido pelo bloqueio do acautelamento'), 'RN-010': ('BLOCKED', 'P-03/P-06'), 'RN-011': ('BLOCKED', 'P-05/P-06'),
 'RN-012': ('IN_PROGRESS', 'débito considera reservado (EstoqueTest); operação de reserva bloqueada'), 'RN-013': ('BLOCKED', 'P-02/P-03'), 'RN-014': ('BLOCKED', 'P-03'),
 'RN-015': ('IMPLEMENTED', 'saldo disponível conta só DISPONIVEL; estorno impedido para item em manutenção (EstoqueTest)'), 'RN-016': ('BLOCKED', 'P-03/P-06'),
 'RN-017': ('TESTED', t('est','aud')), 'RN-018': ('TESTED', t('est','conc')), 'RN-019': ('TESTED', t('org','est','doc','e2e-doc') + '; download de documento autorizado por escopo; exportações/relatórios bloqueados (P-12)'),
 'RN-020': ('IN_PROGRESS', 'eventos implementados, inclusive consulta/download de documento; matriz aprovada pendente (P-08)'), 'RN-021': ('TESTED', t('doc','e2e-doc') + ' — disco privado, lista de permissão PDF/PNG/JPG, assinatura+finfo, PDF ativo recusado, nome aleatório, download só autorizado'), 'RN-022': ('NOT_STARTED', 'sem QR no MVP (P-07)'),
 'RN-023': ('NOT_STARTED', 'P-03'), 'RN-024': ('TESTED', t('org','mil','est')), 'RN-025': ('IN_PROGRESS', 'contas desativadas e documentos arquivados, nunca apagados (trigger); retenção/descarte seguro pendentes (P-08)'),
}
MUC = {'UC-01': ('TESTED', t('auth','e2e-auth')), 'UC-02': ('TESTED', t('est','e2e-est')), 'UC-03': ('TESTED', t('mil','cat','e2e-est')), 'UC-04': ('TESTED', t('est','conc','e2e-est')),
 'UC-05': ('BLOCKED', 'P-05'), 'UC-06': ('BLOCKED', 'P-06'), 'UC-07': ('BLOCKED', 'P-06'), 'UC-08': ('BLOCKED', 'P-06/P-07'), 'UC-09': ('BLOCKED', 'P-03'), 'UC-10': ('BLOCKED', 'P-05/P-06'),
 'UC-11': ('BLOCKED', 'P-03'), 'UC-12': ('BLOCKED', 'P-03'), 'UC-13': ('BLOCKED', 'P-12'), 'UC-14': ('TESTED', t('aud','e2e-auth')), 'UC-15': ('TESTED', t('auth','org'))}

APROV = 'PENDENTE (SRS v1.0 em rascunho)'
esc = lambda s: str(s).replace('|', '\\|')
out = ['# Matriz de rastreabilidade — SIGARB', '',
 '> Gerada por `scripts/rastreabilidade.py` a partir de `SRS-EXTRAIDO.md` (definições) e do mapeamento mantido no script. **Não editar à mão.**',
 '> Status: NOT_STARTED · IN_PROGRESS · IMPLEMENTED · TESTED · BLOCKED · ACCEPTED. Nenhum item é ACCEPTED: não houve aceite institucional.',
 '> Caminhos de teste: `backend/tests/…` e `frontend/…`. Backend: `backend/app/Http/Controllers/Api/`, `backend/app/Services/`.', '']
from collections import Counter
cont = Counter()
out += ['## Requisitos funcionais (SRS §3)', '', '| ID | Área | Definição (SRS) | Prior. | RN relac. | UC relac. | Backend | Frontend | API (/api/v1) | Entidades | Testes | Status | Aprovação | Notas |', '|' + '---|' * 14]
for k, (area, req, pri) in RF.items():
    m = M[k]; cont[m['st']] += 1
    out.append('| ' + ' | '.join(esc(x) for x in [k, area, req, pri, m['rn'], m['uc'], m['be'], m['fe'], m['api'], m['ent'], m['testes'], f"**{m['st']}**", APROV, m['notas']]) + ' |')
out += ['', '## Requisitos não funcionais (SRS §4)', '', '| ID | Categoria | Definição (SRS) | Evidência / implementação | Testes | Status | Aprovação |', '|' + '---|' * 7]
for k, (cat, req) in RNF.items():
    st, ev, te = MNF[k]; cont[st] += 1
    out.append('| ' + ' | '.join(esc(x) for x in [k, cat, req, ev, te, f'**{st}**', APROV]) + ' |')
out += ['', '## Regras de negócio (SRS §5)', '', '| ID | Regra (SRS) | Evidência / pendência | Status | Aprovação |', '|' + '---|' * 5]
for k, (req,) in RN.items():
    st, ev = MRN[k]; cont[st] += 1
    out.append('| ' + ' | '.join(esc(x) for x in [k, req, ev, f'**{st}**', APROV]) + ' |')
out += ['', '## Casos de uso (SRS §6)', '', '| ID | Caso de uso | Pré-condição | Resultado | Evidência / pendência | Status |', '|' + '---|' * 6]
for k, (nome, pre, res) in UC.items():
    st, ev = MUC[k]; cont[st] += 1
    out.append('| ' + ' | '.join(esc(x) for x in [k, nome, pre, res, ev, f'**{st}**']) + ' |')
out += ['', '## Pendências (SRS §15)', '', '| ID | Tema | Confirmar | Situação |', '|---|---|---|---|']
for k, (tema, conf) in P.items():
    out.append(f'| {k} | {esc(tema)} | {esc(conf)} | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |')
out += ['', '## Melhorias de engenharia (não constam do SRS)', '', '| ID | Item | Motivo |', '|---|---|---|',
 '| EN-01 | Cadeia de hash na auditoria + verificação | Evidência de adulteração (RNF-04) além de append-only |',
 '| EN-02 | Idempotency-Key obrigatório em escrita de estoque | SRS §11.1 cita idempotência "quando necessário" |',
 '| EN-03 | Dispositivos confiáveis e limitadores por conta/IP | Mitigar força bruta distribuída sem permitir bloqueio da conta por terceiros |',
 '| EN-04 | Portão de políticas (config/sigarb.php) | Torna explícito o que está bloqueado e por qual pendência |',
 '| EN-05 | Mascaramento de matrícula sem `militares.ver_detalhe` | Minimização (P-09) |',
 '| EN-06 | Verificação de checksum a cada download de documento | Detectar arquivo adulterado/corrompido no disco |',
 '| EN-07 | Documento fora do escopo responde 404 (não 403) | Evita enumeração de documentos de outras unidades |',
 '| EN-08 | CSP de produção validada no E2E (com sonda negativa) | Mitigar XSS e injeção de conteúdo |',
 '| EN-09 | Identidades MySQL separadas (runtime só DML) | Reduzir impacto de comprometimento da aplicação; protege triggers |',
 '| EN-10 | Proxies confiáveis explícitos | Impedir falsificação de IP/esquema e bypass de limites |',
 '', '## Totais por status', '', '| Status | Quantidade (RF+RNF+RN+UC) |', '|---|---|'] + [f'| {s} | {cont[s]} |' for s in ['NOT_STARTED','IN_PROGRESS','IMPLEMENTED','TESTED','BLOCKED','ACCEPTED']]
assert len(RF) == 30 and len(RNF) == 15 and len(RN) == 25 and len(UC) == 15 and len(P) == 12, (len(RF), len(RNF), len(RN), len(UC), len(P))
(RAIZ / 'docs/requisitos/MATRIZ-RASTREABILIDADE.md').write_text('\n'.join(out) + '\n', encoding='utf-8')
print(dict(cont))
