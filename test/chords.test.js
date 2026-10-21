import test from 'node:test'
import assert from 'node:assert/strict'
import http from 'node:http'
import { transposeChord, transposeLine, transposeText, isChordLine, usesFlats } from '../public/chords.js'
import { normalize, parseSheet, importFromUrl } from '../importer.js'

test('transposeChord', () => {
  assert.equal(transposeChord('Am7', 2), 'Bm7')
  assert.equal(transposeChord('D/F#', 2), 'E/G#')
  assert.equal(transposeChord('Bb', 2, true), 'C')
  assert.equal(transposeChord('C', 1, true), 'Db')
  assert.equal(transposeChord('C', -1), 'B')
  assert.equal(transposeChord('Am', 12), 'Am')
  assert.equal(transposeChord('Gsus4', 5), 'Csus4')
})

test('isChordLine', () => {
  assert.ok(isChordLine('Am   F   C  G'))
  assert.ok(isChordLine('C  G  (x2)'))
  assert.ok(!isChordLine('Well sometimes I go out by myself'))
  assert.ok(!isChordLine('Am I'))
  assert.ok(!isChordLine('[Verse 1]'))
  assert.ok(!isChordLine('e|--0--1--|'))
  assert.ok(!isChordLine(''))
})

test('transposeLine keeps columns', () => {
  assert.equal(transposeLine('Am        F   C', 2), 'Bm        G   D')
  assert.equal(transposeLine('Am        F   C', 1), 'A#m' + ' '.repeat(7) + 'F#' + '  ' + 'C#')
  assert.equal(transposeLine('C Am', 1, true), 'Db Bbm')
})

test('transposeText only touches chord lines', () => {
  assert.equal(transposeText('Am  F\nI am here\n', 2), 'Bm  G\nI am here\n')
})

test('usesFlats', () => {
  assert.ok(usesFlats('Bb  F\nla la'))
  assert.ok(!usesFlats('Am  F\nBb is a word'.replace('Bb is a word', 'la')))
})

test('normalize strips markers and keeps alignment', () => {
  assert.equal(normalize('[tab][ch]Am[/ch]   [ch]F[/ch]\r\nHello[/tab]\n\n\n\nBye  '), 'Am   F\nHello\n\nBye\n')
})

test('normalize lifts inline UG and ChordPro chords', () => {
  assert.equal(
    normalize("[ch]Am[/ch]Well it's [ch]F[/ch]ok\n"),
    "Am        F\nWell it's ok\n",
  )
  assert.equal(
    normalize("[Am]Well it's [F]ok\n"),
    "Am        F\nWell it's ok\n",
  )
  assert.equal(normalize('[Verse 1]\n[Am]hello\n'), '[Verse 1]\nAm\nhello\n')
})

test('parseSheet pulls Key and Capo headers', () => {
  const s = parseSheet('Key: Am\nCapo: 2\nAm   F\nhello\n')
  assert.equal(s.key, 'Am')
  assert.equal(s.capo, 2)
  assert.equal(s.body, 'Am   F\nhello\n')
})

test('importFromUrl reads the Ultimate Guitar page data', async () => {
  const data = { store: { page: { data: {
    tab: { song_name: "Valerie", artist_name: 'Mark Ronson & Amy' },
    tab_view: { meta: { tonality: 'Am', capo: 2 }, wiki_tab: { content: '[Verse 1]\n[ch]Am[/ch]   [ch]F[/ch]\nWell it\'s "ok"' } },
  } } } }
  const attr = JSON.stringify(data).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;')
  const server = http.createServer((_, res) => res.end(`<html><div class="js-store" data-content="${attr}"></div></html>`))
  await new Promise(r => server.listen(0, '127.0.0.1', r))
  try {
    const s = await importFromUrl(`http://127.0.0.1:${server.address().port}/`)
    assert.equal(s.title, 'Valerie')
    assert.equal(s.artist, 'Mark Ronson & Amy')
    assert.equal(s.key, 'Am')
    assert.equal(s.capo, 2)
    assert.equal(s.body, '[Verse 1]\nAm   F\nWell it\'s "ok"\n')
  } finally { server.close() }
})

test('importFromUrl falls back to a <pre> block', async () => {
  const pre = 'Am       F\nSome lyrics go here for a bit\nC        G\nAnd some more lyrics go here too'
  const server = http.createServer((_, res) => res.end(`<title>Test Song Chords</title><pre>${pre}</pre>`))
  await new Promise(r => server.listen(0, '127.0.0.1', r))
  try {
    const s = await importFromUrl(`http://127.0.0.1:${server.address().port}/`)
    assert.equal(s.title, 'Test Song')
    assert.ok(s.body.startsWith('Am       F\n'))
  } finally { server.close() }
})
