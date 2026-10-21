import test, { before, after } from 'node:test'
import assert from 'node:assert/strict'
import http from 'node:http'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { spawn } from 'node:child_process'

const root = path.resolve(import.meta.dirname, '..')
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'chords-'))
const SONGS = path.join(tmp, 'songs'), DATA = path.join(tmp, 'data')
const PORT = 20000 + (process.pid % 10000)
const base = `http://127.0.0.1:${PORT}`
let srv, fake, cookie = '', page = 'Am        F\nfirst version of the lyrics here'

const ugPage = body => {
  const data = { store: { page: { data: { tab: { song_name: 'Valerie', artist_name: 'Mark Ronson' }, tab_view: { meta: { tonality: 'Am' }, wiki_tab: { content: body } } } } } }
  return `<div class="js-store" data-content="${JSON.stringify(data).replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"></div>`
}

before(async () => {
  fs.mkdirSync(DATA, { recursive: true })
  fs.writeFileSync(path.join(DATA, 'shares.json'), JSON.stringify({ expiredtokenexpiredtoken: { type: 'song', id: 'x', created: '2020-01-01T00:00:00Z', expires: '2020-02-01T00:00:00Z' } }))
  fake = http.createServer((_, res) => res.end(ugPage(page)))
  await new Promise(r => fake.listen(0, '127.0.0.1', r))
  srv = spawn(process.execPath, ['server.js'], { cwd: root, env: { ...process.env, PORT, PASSWORD: 'pw', SONGS_DIR: SONGS, DATA_DIR: DATA } })
  await new Promise((res, rej) => { srv.stdout.on('data', d => String(d).includes('Chords on') && res()); srv.on('exit', rej); setTimeout(() => rej(new Error('server did not start')), 8000) })
  const r = await fetch(base + '/login', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'password=pw', redirect: 'manual' })
  cookie = r.headers.get('set-cookie').split(';')[0]
})
after(() => { srv?.kill(); fake?.close(); fs.rmSync(tmp, { recursive: true, force: true }) })

const call = async (p, { method = 'GET', body, auth = true } = {}) => {
  const r = await fetch(base + p, { method, redirect: 'manual', headers: { ...(auth && { cookie }), ...(body !== undefined && { 'content-type': 'application/json' }) }, body: body === undefined ? undefined : JSON.stringify(body) })
  return { status: r.status, json: await r.clone().json().catch(() => null), res: r }
}

test('auth: everything private is locked, shared code is public', async () => {
  assert.equal((await call('/api/songs', { auth: false })).status, 401)
  assert.equal((await call('/api/setlists', { auth: false })).status, 401)
  assert.equal((await call('/api/medleys/x/full', { auth: false })).status, 401)
  assert.equal((await call('/api/prefs/song:x', { auth: false })).status, 401)
  assert.equal((await call('/', { auth: false })).status, 302)
  assert.equal((await call('/app.js', { auth: false })).status, 302)
  assert.equal((await call('/song.js', { auth: false })).status, 200)
  assert.equal((await call('/sw.js', { auth: false })).status, 200)
  assert.equal((await call('/api/shared/expiredtokenexpiredtoken', { auth: false })).status, 404)
  assert.equal((await call('/api/shared/not-a-real-token-at-all', { auth: false })).status, 404)
  assert.equal((await call('/s/not-a-real-token-at-all', { auth: false })).status, 404)
})

test('songs: import, search, opened, prefs sync', async () => {
  const a = await call('/api/songs/import', { method: 'POST', body: { title: 'Yellow', artist: 'Coldplay', text: '[ch]C[/ch]  [ch]G[/ch]\nLook at the stars' } })
  assert.equal(a.json.id, 'coldplay-yellow')
  const b = await call('/api/songs/import', { method: 'POST', body: { url: `http://127.0.0.1:${fake.address().port}/v` } })
  assert.equal(b.json.id, 'mark-ronson-valerie')
  assert.equal((await call('/api/songs?q=stars')).json.length, 1)

  assert.equal((await call('/api/songs/coldplay-yellow/opened', { method: 'POST' })).status, 200)
  const list = (await call('/api/songs')).json
  assert.equal(list.find(s => s.id === 'coldplay-yellow').openCount, 1)
  assert.ok(list.find(s => s.id === 'coldplay-yellow').lastOpened)
  assert.equal(list.find(s => s.id === 'mark-ronson-valerie').openCount, 0)

  assert.equal((await call('/api/prefs/song:coldplay-yellow', { method: 'PUT', body: { t: 2, c: 99, speed: 8, size: 22, flats: true, junk: 1 } })).status, 200)
  assert.equal((await call('/api/prefs/_global', { method: 'PUT', body: { size: 24 } })).status, 200)
  const p = (await call('/api/prefs/song:coldplay-yellow')).json
  assert.deepEqual(p.prefs, { t: 2, c: 11, flats: true, speed: 8, size: 22 })
  assert.equal(p.global.size, 24)
  assert.equal((await call('/api/prefs/..%2Fetc', { method: 'PUT', body: {} })).status, 400)
})

test('re-import previews, replaces, and keeps 5 old versions', async () => {
  page = 'Am        F\nsecond version of the lyrics'
  const r = await call('/api/songs/mark-ronson-valerie/reimport', { method: 'POST' })
  assert.match(r.json.body, /second version/)
  assert.match(r.json.current, /Am/)
  assert.equal((await call('/api/songs/mark-ronson-valerie')).json.body.includes('second version'), false) // preview does not save
  for (let i = 0; i < 7; i++) {
    const x = await call('/api/songs/mark-ronson-valerie/replace', { method: 'POST', body: { body: `Am\nversion ${i}` } })
    assert.equal(x.status, 200)
    await new Promise(r => setTimeout(r, 3))
  }
  assert.equal(fs.readdirSync(path.join(SONGS, '.history')).filter(f => f.startsWith('mark-ronson-valerie.')).length, 5)
  const s = (await call('/api/songs/mark-ronson-valerie')).json
  assert.equal(s.title, 'Valerie')
  assert.equal(s.key, 'Am')
  assert.match(s.body, /version 6/)
  assert.equal((await call('/api/songs/coldplay-yellow/reimport', { method: 'POST' })).status, 422) // pasted, no source
})

test('preview does not save; confirm-save and updateMeta replace', async () => {
  const before = (await call('/api/songs')).json.length
  const prev = await call('/api/songs/preview', {
    method: 'POST',
    body: { text: 'Key: G\nCapo 3\n[G]Hello [C]there', title: 'Preview Me', artist: 'Tester' },
  })
  assert.equal(prev.status, 200)
  assert.equal(prev.json.title, 'Preview Me')
  assert.equal(prev.json.key, 'G')
  assert.equal(prev.json.capo, 3)
  assert.match(prev.json.body, /^G\s+C\nHello there\n$/)
  assert.equal((await call('/api/songs')).json.length, before)

  const saved = await call('/api/songs/import', {
    method: 'POST',
    body: {
      title: prev.json.title, artist: prev.json.artist, key: 'D', capo: 1,
      body: prev.json.body, source: '', text: 'ignored once body is set',
    },
  })
  assert.equal(saved.status, 200)
  assert.equal(saved.json.id, 'tester-preview-me')
  const song = (await call('/api/songs/tester-preview-me')).json
  assert.equal(song.key, 'D')
  assert.equal(song.capo, 1)
  assert.match(song.body, /Hello there/)

  const kept = await call('/api/songs/tester-preview-me/replace', {
    method: 'POST', body: { body: 'G  C\nnew words', key: 'Em', capo: 5 },
  })
  assert.equal(kept.json.key, 'D')
  assert.equal(kept.json.capo, 1)
  assert.match(kept.json.body, /new words/)

  const updated = await call('/api/songs/tester-preview-me/replace', {
    method: 'POST', body: { body: 'Am  F\nagain', key: 'Em', capo: 5, updateMeta: true },
  })
  assert.equal(updated.json.key, 'Em')
  assert.equal(updated.json.capo, 5)
})

test('setlists and medleys', async () => {
  const sl = (await call('/api/setlists', { method: 'POST', body: { name: 'Friday gig', items: [{ songId: 'coldplay-yellow', t: 40, c: -3 }, { songId: '../bad' }] } })).json
  assert.equal(sl.id, 'friday-gig')
  assert.deepEqual(sl.items, [{ songId: 'coldplay-yellow', t: 11, c: 0 }])
  await call('/api/setlists/friday-gig', { method: 'PUT', body: { items: [{ songId: 'mark-ronson-valerie', t: 1, c: 0 }, { songId: 'coldplay-yellow', t: 0, c: 2 }] } })
  const full = (await call('/api/setlists/friday-gig/full')).json
  assert.deepEqual(full.setlist.items.map(i => i.songId), ['mark-ronson-valerie', 'coldplay-yellow'])
  assert.equal(Object.keys(full.songs).length, 2)
  assert.equal((await call('/api/setlists')).json[0].count, 2)

  const m = (await call('/api/medleys', { method: 'POST', body: { name: 'Mix', targetKey: 'G', items: [{ songId: 'coldplay-yellow', t: 0, c: 0, sections: ['Chorus'] }] } })).json
  assert.equal(m.targetKey, 'G')
  assert.deepEqual(m.items[0].sections, ['Chorus'])
  assert.equal((await call('/api/medleys/mix/full')).json.type, 'medley')
  assert.equal((await call('/api/setlists', { method: 'POST', body: { name: '  ' } })).status, 400)
  assert.equal((await call('/api/medleys/mix', { method: 'DELETE' })).status, 200)
  assert.equal((await call('/api/medleys/mix')).status, 404)
})

test('share links: read-only, scoped, revocable', async () => {
  await call('/api/songs/coldplay-yellow', { method: 'PUT', body: { title: 'Yellow', artist: 'Coldplay', key: 'C', capo: 0, tags: 'secret-tag', body: 'C  G\nLook at the stars' } })
  const link = (await call('/api/shares', { method: 'POST', body: { type: 'song', id: 'coldplay-yellow', expires: '7d' } })).json
  assert.match(link.token, /^[A-Za-z0-9_-]{16,}$/)
  assert.ok(link.expires)

  const shared = await call('/api/shared/' + link.token, { auth: false })
  assert.equal(shared.status, 200)
  assert.equal(shared.json.song.title, 'Yellow')
  assert.equal(JSON.stringify(shared.json).includes('secret-tag'), false)
  assert.equal('source' in shared.json.song, false)
  assert.equal((await call('/s/' + link.token, { auth: false })).status, 200)
  // the link opens nothing else
  assert.equal((await call('/api/songs/mark-ronson-valerie', { auth: false })).status, 401)
  assert.equal((await call('/api/songs', { auth: false })).status, 401)
  assert.equal((await call('/api/shares?type=song&id=coldplay-yellow', { auth: false })).status, 401)

  const sl = (await call('/api/shares', { method: 'POST', body: { type: 'setlist', id: 'friday-gig', expires: 'never' } })).json
  const sb = (await call('/api/shared/' + sl.token, { auth: false })).json
  assert.equal(sb.type, 'setlist')
  assert.equal(Object.keys(sb.songs).length, 2)
  assert.equal(JSON.stringify(sb).includes('127.0.0.1'), false) // no source URLs

  assert.equal((await call('/api/shares?type=song&id=coldplay-yellow')).json.length, 1)
  assert.equal((await call('/api/shares/' + link.token, { method: 'DELETE' })).status, 200)
  assert.equal((await call('/api/shared/' + link.token, { auth: false })).status, 404)
  assert.equal((await call('/s/' + link.token, { auth: false })).status, 404)
  assert.equal((await call('/api/shares', { method: 'POST', body: { type: 'song', id: 'nope' } })).status, 404)
  assert.equal((await call('/api/shares', { method: 'POST', body: { type: 'bogus', id: 'x' } })).status, 404)
})

test('hand-edited files are picked up and delete works', async () => {
  fs.writeFileSync(path.join(SONGS, 'me-hand-made.txt'), '---\ntitle: Hand made\nartist: Me\n---\nG  D\nla la\n')
  await new Promise(r => setTimeout(r, 900))
  assert.ok((await call('/api/songs')).json.some(s => s.id === 'me-hand-made'))
  assert.equal((await call('/api/songs/me-hand-made', { method: 'DELETE' })).status, 200)
  assert.equal(fs.existsSync(path.join(SONGS, 'me-hand-made.txt')), false)
})
