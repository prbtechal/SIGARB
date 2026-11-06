import { describe, expect, it } from 'vitest'
import { MAX_BYTES, tamanho, validarArquivo } from './arquivos'

const arq = (nome: string, bytes: number) => new File([new Uint8Array(bytes)], nome)

describe('validação local de arquivo (espelho do servidor)', () => {
  it('aceita PDF/PNG/JPG dentro do limite', () => {
    for (const n of ['a.pdf', 'b.PNG', 'c.jpg', 'd.jpeg']) expect(validarArquivo(arq(n, 10))).toBeNull()
  })
  it('recusa formatos fora da lista, sem extensão, vazio e grande demais', () => {
    expect(validarArquivo(arq('x.exe', 10))).toMatch(/Formato/)
    expect(validarArquivo(arq('x.svg', 10))).toMatch(/Formato/)
    expect(validarArquivo(arq('semextensao', 10))).toMatch(/Formato/)
    expect(validarArquivo(arq('x.pdf', 0))).toMatch(/vazio/)
    expect(validarArquivo(arq('x.pdf', MAX_BYTES + 1))).toMatch(/10 MB/)
    expect(validarArquivo(null)).toMatch(/Selecione/)
  })
  it('formata tamanho', () => {
    expect(tamanho(512)).toBe('512 B')
    expect(tamanho(2048)).toBe('2 KB')
  })
})
