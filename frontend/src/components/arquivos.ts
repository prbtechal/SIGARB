// Espelho da lista de permissão do servidor (config/sigarb.php). O servidor revalida tudo.
export const FORMATOS = ['pdf', 'png', 'jpg', 'jpeg'] as const
export const ACCEPT = '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg'
export const MAX_BYTES = 10 * 1024 * 1024

export function validarArquivo(f: File | null | undefined): string | null {
  if (!f) return 'Selecione um arquivo.'
  const ext = f.name.includes('.') ? f.name.split('.').pop()!.toLowerCase() : ''
  if (!(FORMATOS as readonly string[]).includes(ext)) return 'Formato não permitido. Aceitos: PDF, PNG, JPG.'
  if (f.size === 0) return 'O arquivo está vazio.'
  if (f.size > MAX_BYTES) return 'O arquivo excede o limite de 10 MB.'
  return null
}

export function tamanho(b: number): string {
  if (b < 1024) return `${b} B`
  if (b < 1048576) return `${(b / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} KB`
  return `${(b / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`
}
