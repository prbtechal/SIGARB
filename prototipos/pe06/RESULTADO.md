# PE-06 — Inspeção/sanitização de PDF (2026-11-06)

Ambiente: `debian:stable-slim`, qpdf 12.2.0, Ghostscript 10.05.1; `--network none`, FS somente-leitura, 512 MB.
Reproduzir: `prototipos/pe06/executar.sh`. Fixtures sintéticas geradas pelo script (o "JavaScript" é texto inerte).

| Fixture | A) heurística atual (produção) | B) qpdf `--qdf --object-streams=disable` + mesma varredura | C) Ghostscript `pdfwrite` |
|---|---|---|---|
| limpo | aceita | limpo (~10 ms) | sem conteúdo ativo (~140 ms) |
| JS explícito | recusa (ativo) | **ativo detectado** | **JS PERMANECE** |
| JS com nome `#xx` | recusa | **ativo detectado** (nomes normalizados) | **JS PERMANECE** |
| JS dentro de `/ObjStm` | recusa (não verificável) | **ativo detectado** (stream descomprimido) | **JS PERMANECE** |

> **Ressalva:** 4 fixtures sintéticas cobrem casos conhecidos; isso **não** demonstra que PDFs maliciosos arbitrários sejam detectados. Antes de qualquer adoção: corpus amplo de PDFs maliciosos públicos de pesquisa, fuzzing do qpdf isolado e revisão de segurança.

## Conclusões
1. **Ghostscript `pdfwrite` não é sanitizador**: preservou o JavaScript nos três casos. Descartado como mecanismo de segurança.
2. **qpdf + varredura** dá diagnóstico preciso e permitiria **aceitar PDFs modernos legítimos** (com object streams) que hoje são recusados, mantendo a recusa de conteúdo ativo. Custo ~10 ms/arquivo.
3. A heurística atual continua segura (falha fechada), ao custo de recusar PDFs legítimos com `/ObjStm`.
4. Antivírus (ClamAV) não avaliado: base de assinaturas grande e atualização em rede exigem decisão de infraestrutura.

## Riscos e manutenção
qpdf é uma dependência nativa (C++) que processa entrada hostil: precisa rodar **isolado** (container sem rede, sem privilégios, limites de CPU/memória/tempo) e ter atualização de segurança acompanhada.

## Decisão institucional necessária (PE-06)
- Autorizar binário nativo (qpdf) em container isolado no servidor de aplicação?
- Política para PDFs com object streams: recusar (atual) ou normalizar + inspecionar?
- Antivírus obrigatório? Onde e com que atualização de assinaturas?
