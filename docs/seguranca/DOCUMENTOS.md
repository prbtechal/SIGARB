# Segurança do módulo de documentos (RF-014 / RN-021) — ADR-011

## Controles implementados
| Ameaça | Controle | Evidência |
|---|---|---|
| Arquivo executável/ativo | Lista de permissão **PDF, PNG, JPG** (`config/sigarb.php`); extensão + assinatura (magic bytes) + `finfo` precisam concordar | DocumentoTest: formatos, conteúdo incompatível |
| PDF com script/ação/anexo | Recusa `/JavaScript /JS /Launch /EmbeddedFile /OpenAction /AA /RichMedia /XFA`; recusa nomes com escape `#xx` e `/ObjStm` (não verificáveis sem parser) | DocumentoTest: conteúdo ativo, não verificável |
| Validador "falha aberta" | Qualquer erro do PCRE recusa o arquivo | DocumentoTest: backtracking catastrófico |
| PDF truncado | Exige `%%EOF` no final | DocumentoTest |
| Arquivo vazio / grande demais | Tamanho > 0 e ≤ 10 MB (app); `upload_max_filesize=11M`, `post_max_size=12M` (PHP) → 413 padronizado | DocumentoTest |
| Path traversal / sobrescrita | Nome em disco = 256 bits aleatórios (`documentos/aa/bb/<hex64>`), sem extensão; nome original só para exibição, saneado (sem `/ \ \0 CR LF " < > : \| ? *`, sem ponto inicial) | DocumentoTest: traversal, colisão; ConcorrenciaTest: 6 uploads simultâneos |
| Exposição pública | Disco `documentos` em `storage/app/private`, `serve=false`, sem URL; caminho nunca sai da API | DocumentoTest |
| Enumeração | URL usa ULID; fora do escopo → 404 idêntico ao inexistente; ID interno não funciona | DocumentoTest, e2e documentos |
| Acesso horizontal/vertical | Permissão + escopo por subárvore em toda rota; quem enxerga sem `documentos.gerir` → 403 | DocumentoTest |
| Conta inativa / sessão revogada | Middleware de conta ativa; sessões no banco | DocumentoTest (401 CONTA_INATIVA), e2e (revogação → 401) |
| Renderização de conteúdo não confiável | Sempre `Content-Disposition: attachment`, `nosniff`, `CSP: default-src 'none'; sandbox`, `no-store`, CORP same-origin | DocumentoTest, e2e |
| Arquivo adulterado/ausente no disco | SHA-256 verificado a cada download; divergência → 409 + auditoria FALHA | DocumentoTest |
| Troca do arquivo após envio | Trigger impede alterar `armazenamento_id/sha256/tamanho/mime/unidade/autor/codigo`; documento e vínculo não podem ser apagados | DocumentoTest |
| Abuso de upload | `throttle:20,1` por usuário na rota de envio | rota |

## Auditoria
`documento.enviado`, `documento.consulta` (detalhe = consulta sensível, política provisória P-08), `documento.download`, `documento.metadados` (antes/depois), `documento.arquivado|reativado` (com justificativa), `documento.vinculado`, `documento.acesso_negado` (com operação e se existe), `documento.vinculo_negado`, `documento.integridade` (FALHA). Nunca registra o conteúdo do arquivo.

## Limitações conhecidas
- A inspeção de PDF é **heurística**, não um parser. O risco residual é mitigado por nunca renderizar inline (anexo + sandbox). A recusa de `/ObjStm` rejeita alguns PDFs modernos legítimos; a orientação ao usuário é "Imprimir como PDF".
- Sem antivírus. **Proposta CP-6:** sanitizar por reescrita (qpdf/Ghostscript em container isolado) e/ou ClamAV, se aprovado pela segurança institucional.
- Arquivo e metadados não são cifrados em repouso no MVP (depende de P-01/P-09).
- Classificação de sigilo e prazo de retenção **não** foram modelados (P-09, P-08). Tipos de documento são genéricos e provisórios (PE-05).
- Emissão de documento oficial (carteira, termo assinado) não existe (P-07, P-12).
