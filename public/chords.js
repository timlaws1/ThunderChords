// Chord detection and transposing. Plain ES module, runs in the browser and in Node tests.
const SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B']
const FLAT = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B']
export const IDX = { C: 0, 'C#': 1, Db: 1, D: 2, 'D#': 3, Eb: 3, E: 4, Fb: 4, 'E#': 5, F: 5, 'F#': 6, Gb: 6, G: 7, 'G#': 8, Ab: 8, A: 9, 'A#': 10, Bb: 10, B: 11, Cb: 11, 'B#': 0 }

export const CHORD = /^([A-G][#b]?)((?:maj|min|m|M|dim|aug|sus|add|no|[0-9+°ø\-#b()])*)(?:\/([A-G][#b]?))?$/
const NEUTRAL = /^(?:[|/\-]+|\(?x\d+\)?|N\.?C\.?|%|[:.|]+)$/i
const TUNINGS = new Set(['EADGBE', 'DADGBE', 'DADGAD', 'DGDGBD', 'DADF#AD', 'EbAbDbGbBbEb', 'CGCFAD', 'DGCFAD'])
const TAB = /^\s*[a-gA-G][#b]?\s*[|:]\s*[-\dhpbrx/\\~^()|*.\s<>]{3,}$/
const SECTION = /^\s*\[[^\]]+\]\s*$/

const shift = (note, n, flats) => (flats ? FLAT : SHARP)[(((IDX[note] + n) % 12) + 12) % 12]

export function transposeChord(tok, n, flats = false) {
  const m = tok.match(CHORD)
  if (!m) return tok
  return shift(m[1], n, flats) + m[2] + (m[3] ? '/' + shift(m[3], n, flats) : '')
}

// A chord line holds only chords (plus bars, x2, N.C.) and at least one chord.
export function isChordLine(line) {
  const toks = line.trim().split(/\s+/).filter(Boolean)
  let chords = 0
  for (const t of toks) {
    if (CHORD.test(t)) chords++
    else if (!NEUTRAL.test(t)) return false
  }
  return chords > 0
}

// "E A D G B E" looks like chords but is a tuning. Only known tunings of 5+ strings count.
export function isTuningLine(line) {
  const t = line.trim().split(/\s+/)
  return t.length >= 5 && TUNINGS.has(t.join(''))
}

// Label every line: blank, divider, section, tab, tuning, chord or lyric.
// A line starting with a backslash is always lyrics. A lone "A" directly under a chord line is
// lyrics too (a chord line is followed by its lyric, so that "A" is the word, not the chord).
export function classify(lines) {
  const types = []
  lines.forEach((l, i) => {
    const t = l.trim()
    let type
    if (!t) type = 'blank'
    else if (/^===.+===$/.test(t)) type = 'divider'
    else if (SECTION.test(l)) type = 'section'
    else if (TAB.test(l)) type = 'tab'
    else if (t.startsWith('\\')) type = 'lyric'
    else if (isTuningLine(l)) type = 'tuning'
    else if (isChordLine(l)) type = t === 'A' && types[i - 1] === 'chord' ? 'lyric' : 'chord'
    else type = 'lyric'
    types.push(type)
  })
  return types
}

// Transpose a chord line but keep each chord starting in its original column,
// so chords stay above the right syllable even when C becomes C#.
export function transposeLine(line, n, flats = false) {
  let out = ''
  for (const m of line.matchAll(/\S+/g)) {
    const need = m.index - out.length
    if (need > 0) out += ' '.repeat(need)
    else if (out.length) out += ' '
    out += transposeChord(m[0], n, flats)
  }
  return out
}

export function transposeText(text, n, flats = false) {
  if (!n) return text
  const lines = text.split('\n')
  const types = classify(lines)
  return lines.map((l, i) => (types[i] === 'chord' ? transposeLine(l, n, flats) : l)).join('\n')
}

// Every chord token in the text, in order.
export function chordsIn(text) {
  const lines = text.split('\n')
  const types = classify(lines)
  return lines.flatMap((l, i) => (types[i] === 'chord' ? l.trim().split(/\s+/).filter(t => CHORD.test(t)) : []))
}

// Does this sheet already spell its chords with flats? Used to pick a default.
export function usesFlats(text) {
  return chordsIn(text).some(t => { const m = t.match(CHORD); return m[1].endsWith('b') || m[3]?.endsWith('b') })
}
