import { afterEach, describe, expect, it } from 'vitest'
import { novaChave } from './client'

const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('novaChave', () => {
  const original = Object.getOwnPropertyDescriptor(crypto, 'randomUUID')
  afterEach(() => {
    if (original) Object.defineProperty(crypto, 'randomUUID', original)
    else delete (crypto as { randomUUID?: unknown }).randomUUID
  })

  it('gera UUID v4 mesmo sem crypto.randomUUID (HTTP fora de contexto seguro)', () => {
    Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true, writable: true })
    const a = novaChave()
    const b = novaChave()
    expect(a).toMatch(V4)
    expect(b).toMatch(V4)
    expect(a).not.toBe(b)
  })

  it('usa crypto.randomUUID quando disponível', () => {
    expect(novaChave()).toMatch(V4)
  })
})
