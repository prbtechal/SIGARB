/**
 * Marca PROVISÓRIA do SIGARB: monograma tipográfico em ladrilho branco (mesma convenção de apresentação
 * do Sistema-de-escalas: logo em ladrilho branco sobre o menu azul). Não é brasão nem emblema oficial;
 * substituir por ativo institucional quando houver um aprovado para o SIGARB.
 */
export default function Logo({ grande = false }: { grande?: boolean }) {
  return (
    <span className={`logo${grande ? ' logo-grande' : ''}`} aria-hidden="true">
      <svg viewBox="0 0 48 48" role="presentation" focusable="false">
        <rect x="6" y="6" width="36" height="36" rx="9" fill="#00008b" />
        <path d="M14 33.5h20" stroke="#ffffff" strokeWidth="2.5" strokeLinecap="round" opacity=".55" />
        <text x="24" y="28.5" textAnchor="middle" fontFamily="ui-sans-serif, system-ui, sans-serif" fontWeight="800" fontSize="15" letterSpacing="1" fill="#ffffff">SG</text>
      </svg>
    </span>
  )
}
