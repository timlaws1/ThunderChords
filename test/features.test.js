import test from 'node:test'
import assert from 'node:assert/strict'
import { classify, isChordLine, transposeChord, transposeText } from '../public/chords.js'
import { sectionsOf, hasMarkers, keyInfo, guessKey, shiftFor, orderByKey, suggestCapo, composeMedley, medleySource } from '../public/music.js'
import { moveItem } from '../public/reorder.js'
import { normalize } from '../importer.js'

test('classify: lone A under a chord line is a lyric, otherwise a chord', () => {
  assert.deepEqual(classify(['Am F', 'A', 'some lyric']), ['chord', 'lyric', 'lyric'])
  assert.deepEqual(classify(['A', 'la la la']), ['chord', 'lyric'])
  assert.deepEqual(classify(['\\A', 'x']), ['lyric', 'lyric'])
})

test('classify: tunings and tab lines are never chords', () => {
  assert.deepEqual(classify(['E A D G B E', 'D A D F# A D', 'C G Am F']), ['tuning', 'tuning', 'chord'])
  assert.deepEqual(classify(['e|--0--1--|', 'B|---3-----|']), ['tab', 'tab'])
  assert.equal(transposeText('E A D G B E\ne|--0--|\nAm', 2), 'E A D G B E\ne|--0--|\nBm')
})

test('chord extensions', () => {
  assert.ok(isChordLine('Am(add9) C#m7b5 Bm7/E Dsus2 N.C. E5 G6 F#dim7 Cmaj9'))
  assert.equal(transposeChord('Cmaj9', 2), 'Dmaj9')
  assert.equal(transposeChord('F#dim7', 1), 'Gdim7')
  assert.equal(transposeChord('Am(add9)', 2), 'Bm(add9)')
})

test('import cleanup: sharps and flats symbols, junk lines', () => {
  assert.equal(normalize('A\u266fm  B\u266d\nAdvertisement\nTranspose\nSome words\n'), 'A#m  Bb\nSome words\n')
})

test('sectionsOf numbers repeated sections', () => {
  const s = sectionsOf('[Verse 1]\nAm\nla\n\n[Chorus]\nC\nsing\n\n[Chorus]\nC\nagain')
  assert.deepEqual(s.map(x => x.id), ['Verse 1', 'Chorus', 'Chorus (2)'])
  assert.equal(s[1].text, '[Chorus]\nC\nsing')
  assert.ok(hasMarkers('[Intro]\nAm'))
  assert.ok(!hasMarkers('Am\nla'))
  assert.deepEqual(sectionsOf('Am\nla').map(x => x.id), ['(start)'])
})

test('keys: info, guess, shift', () => {
  assert.deepEqual(keyInfo('Am'), { root: 9, minor: true })
  assert.equal(keyInfo('Cmaj7').minor, false)
  assert.equal(keyInfo('Cm7').minor, true)
  assert.equal(keyInfo('xyz'), null)
  assert.deepEqual(guessKey('Am  F\nlyric', ''), { key: 'Am', guessed: true })
  assert.deepEqual(guessKey('Am  F', 'G'), { key: 'G', guessed: false })
  assert.equal(shiftFor('Am', 'Em'), -5)
  assert.equal(shiftFor('G', 'A'), 2)
  assert.equal(shiftFor('Am', 'C'), 0) // C major target means A minor for a minor song
  assert.equal(shiftFor('C', 'Am'), 0)
})

test('orderByKey walks the circle of fifths', () => {
  assert.deepEqual(orderByKey(['C', 'F#', 'G', 'D', 'Am']), [0, 4, 2, 3, 1])
  assert.deepEqual(orderByKey(['C', null, 'G']), [0, 2, 1])
  assert.deepEqual(orderByKey([null, null]), [0, 1])
})

test('suggestCapo finds open shapes', () => {
  const r = suggestCapo('Bb  Gm  Eb  F\nla la la la')
  assert.equal(r.current.easy, 0)
  assert.equal(r.options[0].c, 3)
  assert.equal(r.options[0].t, 0)
  assert.equal(r.options[0].easy, 1)
  assert.deepEqual(r.options[0].chords, ['G', 'Em', 'C', 'D'])
  assert.equal(suggestCapo('just words').current, null)
})

test('composeMedley picks sections, transposes and adds dividers', () => {
  const songs = {
    a: { id: 'a', title: 'One', key: 'Am', capo: 0, body: '[Verse]\nAm  F\nhello\n\n[Chorus]\nC  G\nsing' },
    b: { id: 'b', title: 'Two', key: 'G', capo: 2, body: 'G  D\nhi' },
  }
  const text = composeMedley([
    { songId: 'a', t: 2, c: 0, sections: ['Chorus'] },
    { songId: 'b', t: 0, c: 0, sections: null },
    { songId: 'gone', t: 0, c: 0 },
  ], songs)
  assert.equal(text, '=== One, key Bm, +2 ===\n[Chorus]\nD  A\nsing\n\n=== Next: Two, key G, capo 2 ===\nG  D\nhi\n\n=== Song removed ===\n(this song was deleted)')
})

test('medley custom body overrides sections; original song untouched', () => {
  const song = { id: 'a', title: 'One', key: 'C', capo: 0, body: '[Verse]\nC  G\nfull\n\n[Chorus]\nAm  F\nchorus' }
  const custom = { songId: 'a', t: 0, c: 0, sections: ['Chorus'], body: 'C  G\nshort only\n' }
  assert.equal(medleySource(custom, song), 'C  G\nshort only')
  assert.equal(medleySource({ songId: 'a', sections: ['Chorus'] }, song), '[Chorus]\nAm  F\nchorus')
  assert.match(composeMedley([custom], { a: song }), /short only/)
  assert.ok(!composeMedley([custom], { a: song }).includes('chorus'))
  assert.equal(song.body.includes('full'), true)
})

test('moveItem', () => {
  assert.deepEqual(moveItem([1, 2, 3], 0, 2), [2, 3, 1])
  assert.deepEqual(moveItem([1, 2, 3], 2, 1), [1, 3, 2])
  const a = [1, 2, 3]
  assert.equal(moveItem(a, 1, 1), a)
  assert.equal(moveItem(a, 0, -1), a)
  assert.equal(moveItem(a, 2, 3), a)
})
