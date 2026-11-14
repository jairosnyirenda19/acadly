import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/*
 * DESIGN.md is the law for the look; tokens.css is how the code reads it.
 * This test fails when the two disagree, so a colour cannot drift.
 */
const here = dirname(fileURLToPath(import.meta.url))
const design = readFileSync(resolve(here, '../../../../DESIGN.md'), 'utf8')
const tokens = readFileSync(resolve(here, 'tokens.css'), 'utf8')

function designColours(): Map<string, string> {
  const block = /^colors:\n((?: {2}.+\n)+)/m.exec(design)?.[1] ?? ''
  const out = new Map<string, string>()

  for (const match of block.matchAll(/^ {2}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
    out.set(match[1] as string, (match[2] as string).toLowerCase())
  }

  return out
}

function cssColours(): Map<string, string> {
  const out = new Map<string, string>()

  for (const match of tokens.matchAll(/--color-([a-z-]+): (#[0-9a-fA-F]{6});/g)) {
    out.set(match[1] as string, (match[2] as string).toLowerCase())
  }

  return out
}

describe('design tokens', () => {
  const fromDesign = designColours()
  const fromCss = cssColours()

  it('reads the colours from DESIGN.md', () => {
    expect(fromDesign.size).toBeGreaterThanOrEqual(20)
  })

  it('has every DESIGN.md colour in tokens.css with the same value', () => {
    for (const [name, value] of fromDesign) {
      expect(fromCss.get(name), `--color-${name}`).toBe(value)
    }
  })

  it('has no colour in tokens.css that DESIGN.md does not name', () => {
    for (const name of fromCss.keys()) {
      expect(fromDesign.has(name), `--color-${name} is not in DESIGN.md`).toBe(true)
    }
  })

  it('keeps radius at zero and declares no shadow', () => {
    expect(tokens).toContain('--radius-none: 0px;')
    expect(tokens).not.toMatch(/--shadow-[a-z0-9]+:\s*(?!initial)/)
  })
})
