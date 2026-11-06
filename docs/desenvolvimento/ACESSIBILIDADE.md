# Acessibilidade e navegadores (CP-6, 2026-11-06)

> **Não há declaração de conformidade WCAG 2.2 AA.** As verificações abaixo são automatizadas e parciais; a verificação manual com leitor de tela ainda **não foi executada** (procedimento abaixo).

## Matriz de navegadores (Playwright 1.65, `scripts/e2e-navegadores.sh`)
| Motor | Versão | Onde roda | Projetos | Resultado |
|---|---|---|---|---|
| Chromium | Chrome headless shell 158 | host | desktop + Pixel 7 | todos os fluxos e axe ✓ |
| Firefox | 159.0 | host | desktop | todos os fluxos ✓; **axe desativado** (ver limitação) |
| WebKit | 27.2 | container oficial `mcr.microsoft.com/playwright:v1.65.0-noble` (exige libs de sistema que não foram instaladas no host) | desktop Safari + iPhone 14 | todos os fluxos e axe ✓ |

Execução de 2026-11-06: Chromium+Firefox 29 passed / 5 skipped; WebKit 16 passed / 3 skipped; 0 failed.
Skips = testes restritos por projeto (ex.: gaveta só no celular, zoom/diálogo só no desktop) + axe no Firefox.

### Diferenças e limitações observadas
- **Firefox 159 + axe-core:** o processo de conteúdo cai durante a injeção do axe (modos `runPartial` e `legacy`), de forma intermitente (~1/3) ou constante (legacy), em telas variadas. Sem o axe: 30 navegações/interações em `/estoque`, 0 crashes; todos os fluxos funcionais passam. Conclusão: problema da ferramenta, não da aplicação. Varredura automática coberta por Chromium e WebKit. Reavaliar ao atualizar Firefox/Playwright/axe.
- **jsdom (Vitest):** não calcula nome acessível de `<figure>` a partir de `<figcaption>` → gráficos usam `aria-labelledby` explícito (robusto em todos os leitores).
- Downloads funcionam igual nos três motores (anexo, conteúdo íntegro).

## O que é verificado automaticamente
| Item | Como |
|---|---|
| Regras axe WCAG 2.0/2.1/2.2 A/AA (sérias e críticas = 0) | 12 telas × Chromium desktop/celular e WebKit desktop/iPhone |
| Teclado no login (Tab/Enter, sem mouse) | e2e autenticação |
| Foco do diálogo: entra no diálogo, Esc fecha, volta ao gatilho | e2e (Chromium, Firefox, WebKit) |
| Zoom equivalente a 200% sem rolagem horizontal | e2e desktop |
| Gaveta de menu (`aria-expanded`), tabelas viram cartões | e2e celular (Pixel 7, iPhone 14) |
| Campos com rótulo, `aria-invalid`, `aria-describedby` (dica + erro) | Vitest + e2e |
| Gráficos com alternativa textual nativa (tabela semântica) | Vitest |
| CSP estrita não quebra nenhuma tela | e2e (Chromium, WebKit) |

## Ordem de foco e teclado (revisão de código)
Link "Pular para o conteúdo" → menu (botão em telas estreitas) → nome/Sair → navegação lateral → conteúdo. Na troca de rota o foco vai para o `<h1>` da página (anúncio por leitor de tela). Diálogos usam `<dialog>` nativo (`showModal`), com foco preso pelo navegador. Abas de listas de referência usam `role="tab"` e `aria-selected`, **sem** navegação por setas (pendência menor).

## Procedimento manual com leitor de tela (a executar)
Leitores: NVDA + Firefox (Windows), Orca + Firefox (Linux) e VoiceOver + Safari (macOS/iOS), conforme os navegadores institucionais (RNF-12).
1. Login: rótulos lidos, erro anunciado (role=alert), foco permanece no formulário.
2. Navegação: "Pular para o conteúdo"; na troca de página o título é anunciado.
3. Painel: cada gráfico é lido como tabela ("Quantidade física por natureza", linhas com valor e %).
4. Nova entrada: campos dinâmicos por modo de controle anunciados; revisão em diálogo; sucesso anunciado (região polite).
5. Documentos: campo de arquivo com dica de formato; progresso anunciado; erro do servidor associado ao campo.
6. Listas de referência: desativar → diálogo explica consequência; botão confirmar desabilitado até justificativa.
7. Tabelas no celular (cartões): cada valor precedido do rótulo da coluna.
Registrar por item: leitor/navegador/versão, resultado, problemas, gravidade. Contraste no tema escuro também deve ser conferido manualmente.
