# Design system institucional (CP-9)

**Referência visual:** `prbtechal/Sistema-de-escalas`, branch `main` @ `849b07c` (2026-11-05), lida em clone descartável somente leitura
(o clone local da estação estava desatualizado em `a592ea2`). Nada foi copiado de autenticação, consultas, regras, permissões, segredos ou ativos de marca.

## Padrões identificados na referência (código-fonte, não inferidos)
| Área | Sistema-de-escalas | SIGARB |
|---|---|---|
| Cor da marca | `brand` 50–950, base `#00008b` (tailwind.config.js) | mesma escala em tokens CSS `--marca-*` |
| Neutros | slate (fundo `slate-100`, cartões brancos `slate-200` borda) | `--n-*` (slate), mesmos papéis |
| Tipografia | piso 14/16px (`text-xs`/`text-sm` redefinidos), título 30px, seção 22px, base 16px | `--fs-aux` 14, `--fs-corpo` 16, `--fs-secao` 22, `--fs-titulo` 30 |
| Foco | contorno 3px `rgba(0,0,139,.6)`, offset 2; branco no menu azul | idêntico (`--foco`), branco no menu/gaveta |
| Menu lateral | `aside` flutuante, gradiente brand-500→700, `rounded-3xl`, recolhido 80px, expande a 256px por hover **e** `focus-within`, "chip" de 40px no ícone, ativo = chip branco | idêntico em CSS; rótulos invisíveis quando recolhido (axe) |
| Celular | faixa fixa azul com botão "Menu", gaveta 320px/85vw, foco preso, Esc fecha, foco volta ao botão | idêntico |
| Barra superior | cartão com avatar de iniciais, "Bem-vindo", nome, selo do papel; botões tema e sair (sair em tom de perigo) | idem + notificações in-app |
| Título de página | ícone em quadrado azul 44px + `h1` 30px + descrição | componente `Pagina` com `icone` |
| Botões | min 48px, `rounded-2xl`, primário azul, secundário com borda, `active:scale(.98)` | `.btn-pri`, `.btn-sec`, `.btn-sec-perigo` |
| Cartões/estatística | `glass-panel` sólido, `rounded-3xl`, ícone em quadrado tingido | `.cartao`, `.cartao-com-icone` |
| Tabelas | cabeçalho `slate-50`, texto aux negrito, linhas com hover | `.tabela`; abaixo de 720px vira cartões (SIGARB mantém) |
| Selos | pílula `rounded-full`, fundo tingido 10%, texto semântico, nunca só cor | `.selo-*` |
| Vazio | ícone em quadrado neutro + título + descrição + ação | `Vazio` com `icone` |
| Ícones | `lucide-react` | `lucide-react` 1.16.0 (fixado) |
| Tema | claro/escuro por classe, escolha do usuário | `data-theme` + preferência do sistema; escolha salva só como conveniência |

## Decisões próprias do SIGARB
- **Identidade:** nome SIGARB e monograma tipográfico provisório (`components/Logo.tsx`), sem brasão, emblema ou ativo de outro sistema.
- **CSS sem Tailwind:** o SIGARB já usa tokens CSS; adotar Tailwind só para copiar a aparência traria build e superfície novos sem ganho funcional.
- **Sem estilos inline** (CSP `style-src 'self'`).
- **Grupos no menu** (Geral, Estoque, Cadastros, Administração, Suporte, Conta): a referência tem lista plana; o SIGARB tem mais áreas.
- **Visibilidade do menu é conveniência**; cada rota da API autoriza no servidor.

## Responsividade
Desktop ≥ 1024px (menu lateral) · tablet/celular < 1024px (faixa + gaveta) · < 768px ações da conta em grade · < 720px tabelas em cartões. Sem rolagem horizontal (teste e2e em celular e zoom 200%).

## Evidência
Capturas antes/depois: `docs/desenvolvimento/capturas/{antes,depois}/` (geradas por `frontend/e2e/capturas.spec.ts` com dados sintéticos).
