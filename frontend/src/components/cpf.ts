/** Formata enquanto digita (000.000.000-00). Texto que não é só dígitos fica como está (contas antigas com identificador). */
export function mascaraCpf(v: string): string {
  if (!/^[\d.\-\s]*$/.test(v)) return v
  const d = v.replace(/\D/g, '').slice(0, 11)
  return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2')
}

/** Login guardado como 11 dígitos aparece formatado; outros identificadores aparecem como estão. */
export const exibirLogin = (l: string) => /^\d{11}$/.test(l) ? mascaraCpf(l) : l
