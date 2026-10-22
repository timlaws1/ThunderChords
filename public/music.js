// Music logic that is not about one line of text: sections, keys, capo finder, medleys.
import { CHORD, IDX, chordsIn, transposeChord, transposeText, usesFlats } from './chords.js'

// ---- sections: "[Verse 1]" marker lines and the lines under them ----
export function sectionsOf(body) {
  const out = []
  const seen = {}
  let cur = null
  for (const l of body.replace(/\s+$/, '').split('\n')) {
    const m = l.match(/^\s*\[([^\]]+)\]\s*$/)
    if (m) {
      const label = m[1].trim()
      seen[label] = (seen[label] || 0) + 1
      cur = { id: seen[label] > 1 ? `${label} (${seen[label]})` : label, lines: [l] }
      out.push(cur)
    } else if (cur) cur.lines.push(l)
    else if (l.trim()) { cur = { id: '(start)', lines: [l] }; out.push(cur) }
  }
  return out.map(s => ({ id: s.id, text: s.lines.join('\n').replace(/\s+$/, '') }))
}
export const hasMarkers = body => sectionsOf(body).some(s => s.id !== '(start)')

// ---- keys ----
export function keyInfo(key) {
  const m = String(key || '').trim().match(CHORD)
  if (!m) return null
  return { root: IDX[m[1]], minor: /^(m|min)(?!aj)/.test(m[2]) }
}

// First chord of the song as a guess when no key is stored.
export function guessKey(body, key) {
  if (keyInfo(key)) return { key, guessed: false }
  const first = chordsIn(body)[0]
  const m = first?.match(CHORD)
  if (!m) return null
  return { key: m[1] + (/^(m|min)(?!aj)/.test(m[2]) ? 'm' : ''), guessed: true }
}

// Semitones (-5..6) to move a song in key `from` so it lands in key `to`. A major target
// for a minor song means its relative minor, and the other way round.
export function shiftFor(from, to) {
  const a = keyInfo(from), b = keyInfo(to)
  if (!a || !b) return 0
  const root = a.minor === b.minor ? b.root : a.minor ? (b.root + 9) % 12 : (b.root + 3) % 12
  const d = (root - a.root + 12) % 12
  return d > 6 ? d - 12 : d
}

const fifths = k => { const i = keyInfo(k); return i ? (((i.root + (i.minor ? 3 : 0)) * 7) % 12) : null }
const dist = (a, b) => { const d = Math.abs(a - b); return Math.min(d, 12 - d) }

// Greedy nearest neighbour on the circle of fifths, starting from the first song.
// Songs without a key go last in their original order. Returns the new order as indices.
export function orderByKey(keys) {
  const pos = keys.map(fifths)
  const known = pos.map((p, i) => (p === null ? -1 : i)).filter(i => i >= 0)
  if (!known.length) return keys.map((_, i) => i)
  const left = new Set(known)
  let cur = left.has(0) ? 0 : known[0]
  const out = [cur]
  left.delete(cur)
  while (left.size) {
    cur = [...left].reduce((best, i) => (dist(pos[i], pos[cur]) < dist(pos[best], pos[cur]) ? i : best))
    out.push(cur)
    left.delete(cur)
  }
  return [...out, ...pos.map((p, i) => (p === null ? i : -1)).filter(i => i >= 0)]
}

// ---- capo finder ----
const EASY = new Set(['C', 'D', 'E', 'G', 'A', 'Am', 'Em', 'Dm', 'C7', 'D7', 'E7', 'G7', 'A7', 'B7', 'Am7', 'Em7', 'Dm7'])

function shapeOf(tok) {
  const m = tok.match(CHORD)
  if (!m) return ''
  const q = m[2]
  const minor = /^(m|min)(?!aj)/.test(q)
  const seventh = /7/.test(q) && !/(maj|M)7/.test(q)
  if (/dim|aug|\+|°|ø/.test(q)) return m[1] + q
  return m[1] + (minor ? 'm' : '') + (seventh ? '7' : '')
}

export function suggestCapo(body, { maxCapo = 7, maxShift = 2, flats = usesFlats(body) } = {}) {
  const toks = chordsIn(body)
  if (!toks.length) return { current: null, options: [] }
  const evalShift = n => {
    const shown = toks.map(t => transposeChord(t, n, flats))
    const easy = shown.filter(t => EASY.has(shapeOf(t))).length / shown.length
    return { easy, chords: [...new Set(shown)] }
  }
  const options = []
  for (let t = -maxShift; t <= maxShift; t++) {
    for (let c = 0; c <= maxCapo; c++) {
      const e = evalShift(t - c)
      options.push({ t, c, easy: e.easy, score: e.easy - 0.01 * Math.abs(t) - 0.005 * c, chords: e.chords })
    }
  }
  options.sort((a, b) => b.score - a.score || a.c - b.c)
  const seen = new Set()
  const top = options.filter(o => { const k = o.chords.join(); if (seen.has(k)) return false; seen.add(k); return true }).slice(0, 3)
  return { current: { easy: evalShift(0).easy, chords: evalShift(0).chords }, options: top }
}

// Sheet text for one medley item: optional custom body, else selected sections, else whole song.
export function medleySource(it, song) {
  if (!song) return ''
  if (typeof it.body === 'string' && it.body.trim()) return it.body.replace(/\s+$/, '')
  if (it.sections?.length) {
    return sectionsOf(song.body).filter(x => it.sections.includes(x.id)).map(x => x.text).join('\n\n')
  }
  return song.body.replace(/\s+$/, '')
}

// ---- medley: items are {songId, t, c, sections?, body?}, songs is {id: song} ----
export function composeMedley(items, songs) {
  return items.map((it, i) => {
    const s = songs[it.songId]
    if (!s) return '=== Song removed ===\n(this song was deleted)'
    const flats = usesFlats(s.body)
    const picked = medleySource(it, s)
    const t = it.t || 0
    const key = s.key ? transposeChord(s.key, t, flats) : ''
    const capo = (s.capo || 0) + (it.c || 0)
    const head = `=== ${i ? 'Next: ' : ''}${s.title}${key ? `, key ${key}` : ''}${capo ? `, capo ${capo}` : ''}${t ? `, ${t > 0 ? '+' : ''}${t}` : ''} ===`
    return `${head}\n${transposeText(picked, t - (it.c || 0), flats)}`
  }).join('\n\n')
}
