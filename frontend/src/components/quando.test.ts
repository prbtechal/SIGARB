import { describe, expect, it } from 'vitest'
import { agoraMaceio, comFuso } from './quando'
import { dataHora } from './ui'

// ADR-015: a API devolve ISO com fuso; a tela mostra sempre a hora de Maceió, qualquer que seja o fuso do navegador.
describe('fuso de Maceió', () => {
  it('dataHora converte instantes UTC e locais para a hora de Maceió', () => {
    expect(dataHora('2026-11-19T17:46:00+00:00')).toBe('19/11/2026, 14:46')
    expect(dataHora('2026-11-19T17:46:00.000000Z')).toBe('19/11/2026, 14:46')
    expect(dataHora('2026-11-19T14:46:00-03:00')).toBe('19/11/2026, 14:46')
  })

  it('virada de dia em UTC ainda é o dia anterior em Maceió', () => {
    expect(dataHora('2026-11-20T01:30:00Z')).toBe('19/11/2026, 22:30')
  })

  it('agoraMaceio e comFuso fazem ida e volta sem deslocar', () => {
    const d = new Date('2026-11-19T17:46:00Z')
    expect(agoraMaceio(d)).toBe('2026-11-19T14:46')
    expect(new Date(comFuso(agoraMaceio(d))).toISOString()).toBe('2026-11-19T17:46:00.000Z')
  })
})
