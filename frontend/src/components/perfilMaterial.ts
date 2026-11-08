import type { RegraPerfil } from '../api/tipos'

/** Rótulos dos campos predefinidos (espelha App\Support\PerfilMaterial::ROTULOS). */
export const ROTULO_CAMPO: Record<string, string> = {
  fabricante_id: 'Fabricante', modelo: 'Modelo', calibre_id: 'Calibre', numero_serie: 'Número de série',
  marca: 'Marca', compatibilidade: 'Compatibilidade', cor: 'Cor', tamanho: 'Tamanho',
  fabricado_em: 'Data de fabricação', validade: 'Validade', nivel_protecao: 'Nível de proteção',
}
export const ATRIBUTOS_MATERIAL = ['marca', 'compatibilidade', 'cor', 'tamanho'] as const

/** Campos com valor que deixam de se aplicar ao trocar de tipo — avisados antes de descartar. */
export function camposDescartados(valores: Record<string, string>, regra: RegraPerfil | undefined, candidatos: readonly string[]): string[] {
  if (!regra) return []
  return candidatos.filter((c) => (valores[c] ?? '') !== '' && !regra.material_perm.includes(c) && !regra.item_perm.includes(c) && !regra.item_obrig.includes(c))
}
