import { serve } from '@hono/node-server'
import { serveStatic } from '@hono/node-server/serve-static'
import { Hono } from 'hono'
import { getCookie, setCookie, deleteCookie } from 'hono/cookie'
import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto'
import fs from 'node:fs/promises'
import { watch } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { collection, jsonStore, slug } from './lib/data.js'
import { importFromUrl, parseSheet } from './importer.js'
import { guessKey } from './public/music.js'

process.chdir(path.dirname(fileURLToPath(import.meta.url)))

const PORT = +process.env.PORT || 3000
const HOST = process.env.HOST || '127.0.0.1'
const PASSWORD = process.env.PASSWORD
const SONGS_DIR = path.resolve(process.env.SONGS_DIR || './songs')
const DATA_DIR = path.resolve(process.env.DATA_DIR || (process.env.SONGS_DIR ? path.join(SONGS_DIR, '..') : './data'))
if (!PASSWORD) { console.error('Set the PASSWORD environment variable (optional: PORT, HOST, SONGS_DIR, DATA_DIR, SECRET).'); process.exit(1) }

const SECRET = process.env.SECRET || createHash('sha256').update('chords:' + PASSWORD).digest('hex')
const TOKEN = createHmac('sha256', SECRET).update('session').digest('hex')
const sha = s => createHash('sha256').update(String(s)).digest()
const same = (a, b) => timingSafeEqual(sha(a), sha(b))
const one = s => String(s ?? '').replace(/[\r\n]+/g, ' ').trim()
const clamp = (v, lo, hi, d = 0) => { const n = Math.round(+v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : d }

// ---------- songs: one text file each, all held in memory ----------
const songs = new Map()

function parse(id, text) {
  const m = text.replace(/\r\n/g, '\n').match(/^---\n([\s\S]*?)\n---\n?([\s\S]*)$/)
  const meta = {}
  if (m) for (const l of m[1].split('\n')) { const i = l.indexOf(':'); if (i > 0) meta[l.slice(0, i).trim()] = l.slice(i + 1).trim() }
  return {
    id, title: meta.title || id, artist: meta.artist || '', key: meta.key || '', capo: +meta.capo || 0,
    tags: (meta.tags || '').split(',').map(s => s.trim()).filter(Boolean),
    source: meta.source || '', added: meta.added || '',
    body: (m ? m[2] : text).replace(/\s+$/, '') + '\n',
  }
}

const serialize = s => `---
title: ${one(s.title)}
artist: ${one(s.artist)}
key: ${one(s.key)}
capo: ${+s.capo || 0}
tags: ${s.tags.map(one).join(', ')}
source: ${one(s.source)}
added: ${s.added}
---
${s.body.replace(/\s+$/, '')}
`

async function load() {
  const files = (await fs.readdir(SONGS_DIR)).filter(f => f.endsWith('.txt'))
  const next = new Map()
  await Promise.all(files.map(async f => next.set(f.slice(0, -4), parse(f.slice(0, -4), await fs.readFile(path.join(SONGS_DIR, f), 'utf8')))))
  songs.clear()
  for (const [k, v] of next) songs.set(k, v)
}

function newId(artist, title) {
  const base = slug(`${artist} ${title}`)
  let id = base, n = 2
  while (songs.has(id)) id = `${base}-${n++}`
  return id
}

const save = async s => { songs.set(s.id, s); await fs.writeFile(path.join(SONGS_DIR, s.id + '.txt'), serialize(s)) }

// Keep the last 5 versions of a song before its body is replaced.
async function snapshot(s) {
  const dir = path.join(SONGS_DIR, '.history')
  await fs.mkdir(dir, { recursive: true })
  await fs.writeFile(path.join(dir, `${s.id}.${Date.now()}.txt`), serialize(s))
  const mine = (await fs.readdir(dir)).filter(f => f.startsWith(s.id + '.') && f.endsWith('.txt')).sort()
  for (const f of mine.slice(0, -5)) await fs.rm(path.join(dir, f), { force: true })
}

await fs.mkdir(SONGS_DIR, { recursive: true })
await load()
let timer
watch(SONGS_DIR, () => { clearTimeout(timer); timer = setTimeout(() => load().catch(console.error), 300) }) // picks up hand edits

// ---------- everything else lives in DATA_DIR ----------
const state = await jsonStore(path.join(DATA_DIR, 'state.json'), {})
for (const k of ['prefs', 'opened', 'global']) state.data[k] ||= {}
const shares = await jsonStore(path.join(DATA_DIR, 'shares.json'), {})
const setlists = await collection(path.join(DATA_DIR, 'setlists'))
const medleys = await collection(path.join(DATA_DIR, 'medleys'))
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, async () => { await Promise.all([state.flush(), shares.flush()]); process.exit(0) })

const pub = s => ({ id: s.id, title: s.title, artist: s.artist, key: s.key, capo: s.capo, body: s.body })

// One shape for "a thing you can view": a song, a setlist or a medley with the songs it needs.
function bundle(type, id) {
  if (!['song', 'setlist', 'medley'].includes(type)) return null
  if (type === 'song') { const s = songs.get(id); return s && { type, song: pub(s) } }
  const item = (type === 'setlist' ? setlists : medleys).get(id)
  if (!item) return null
  const map = {}
  for (const it of item.items) { const s = songs.get(it.songId); if (s) map[s.id] = pub(s) }
  return { type, [type]: item, songs: map }
}

// ---------- app ----------
const app = new Hono()
app.use('*', async (c, next) => { await next(); c.header('X-Robots-Tag', 'noindex') })

const loginPage = (msg = '') => `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Chords</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px system-ui,sans-serif;background:#18202b;color:#e8ebf0}
form{display:grid;gap:10px;width:min(320px,88vw)}h1{margin:0 0 4px;font-size:1.3rem}
input,button{font:inherit;padding:10px 12px;border-radius:6px;border:1px solid #2f3a49;background:#202a37;color:inherit}
button{background:#f4c04e;color:#18202b;border:0;font-weight:600;cursor:pointer}p{margin:0;color:#f08a8a;min-height:1.2em}</style>
<form method="post" action="/login"><h1>Chords</h1><input type="password" name="password" placeholder="Password" autofocus required><button>Sign in</button><p>${msg}</p></form>`

app.get('/login', c => c.html(loginPage()))
app.post('/login', async c => {
  const body = await c.req.parseBody()
  if (same(body.password || '', PASSWORD)) {
    setCookie(c, 'sid', TOKEN, { httpOnly: true, sameSite: 'Lax', path: '/', maxAge: 60 * 60 * 24 * 365, secure: c.req.header('x-forwarded-proto') === 'https' })
    return c.redirect('/')
  }
  await new Promise(r => setTimeout(r, 800))
  return c.html(loginPage('Wrong password.'), 401)
})
app.post('/logout', c => { deleteCookie(c, 'sid', { path: '/' }); return c.redirect('/login') })

// Files a shared page needs. Code only, no data.
const PUBLIC = new Set(['/login', '/style.css', '/chords.js', '/music.js', '/sheet.js', '/scroll.js', '/viewer.js', '/song.js', '/api.js',
  '/manifest.webmanifest', '/sw.js', '/icon.svg', '/icon-192.png', '/icon-512.png', '/apple-touch-icon.png'])

app.use('*', async (c, next) => {
  const p = c.req.path
  if (PUBLIC.has(p) || p.startsWith('/s/') || p.startsWith('/api/shared/')) return next()
  const sid = getCookie(c, 'sid')
  if (sid && same(sid, TOKEN)) return next()
  return p.startsWith('/api/') ? c.json({ error: 'Not signed in' }, 401) : c.redirect('/login')
})

// ---------- share links ----------
const hits = new Map()
function limited(c) {
  const ip = (c.req.header('x-forwarded-for') || '').split(',')[0].trim() || 'local'
  const now = Date.now()
  const h = (hits.get(ip) || []).filter(t => now - t < 60000)
  h.push(now)
  hits.set(ip, h)
  if (hits.size > 5000) hits.clear()
  return h.length > 60
}

function shareFor(token) {
  if (!/^[A-Za-z0-9_-]{16,40}$/.test(token) || !Object.hasOwn(shares.data, token)) return null
  const s = shares.data[token]
  if (s.expires && Date.parse(s.expires) < Date.now()) { delete shares.data[token]; shares.save(); return null }
  return s
}

app.get('/s/:token', (c, next) => {
  c.header('Cache-Control', 'no-store')
  if (limited(c)) return c.text('Too many requests', 429)
  if (!shareFor(c.req.param('token'))) return c.text('This link has expired or was removed.', 404)
  return serveStatic({ path: './public/song.html' })(c, next)
})

app.get('/api/shared/:token', c => {
  c.header('Cache-Control', 'no-store')
  if (limited(c)) return c.json({ error: 'Too many requests' }, 429)
  const s = shareFor(c.req.param('token'))
  const b = s && bundle(s.type, s.id)
  return b ? c.json(b) : c.json({ error: 'This link has expired or was removed.' }, 404)
})

const DAYS = { '7d': 7, '30d': 30, never: 0 }

app.post('/api/shares', async c => {
  const b = await c.req.json().catch(() => ({}))
  if (!bundle(b.type, b.id)) return c.json({ error: 'Nothing to share.' }, 404)
  const days = Object.hasOwn(DAYS, b.expires) ? DAYS[b.expires] : 30
  const token = randomBytes(16).toString('base64url')
  const rec = { type: b.type, id: b.id, created: new Date().toISOString(), expires: days ? new Date(Date.now() + days * 864e5).toISOString() : null }
  shares.data[token] = rec
  shares.save()
  return c.json({ token, url: '/s/' + token, ...rec })
})

app.get('/api/shares', c => {
  const { type, id } = c.req.query()
  return c.json(Object.keys(shares.data).filter(t => shareFor(t) && shares.data[t].type === type && shares.data[t].id === id)
    .map(t => ({ token: t, url: '/s/' + t, ...shares.data[t] })))
})

app.delete('/api/shares/:token', c => {
  const t = c.req.param('token')
  if (!Object.hasOwn(shares.data, t)) return c.json({ error: 'Link not found' }, 404)
  delete shares.data[t]
  shares.save()
  return c.json({ ok: true })
})

// ---------- prefs (synced settings) ----------
function cleanPrefs(b) {
  const o = {}
  if ('t' in b) o.t = clamp(b.t, -11, 11)
  if ('c' in b) o.c = clamp(b.c, 0, 11)
  if ('flats' in b) o.flats = !!b.flats
  if ('speed' in b) o.speed = clamp(b.speed, 1, 30, 5)
  if ('size' in b) o.size = clamp(b.size, 10, 40, 17)
  return o
}
const PKEY = /^(song|medley):[a-z0-9-]+$/

app.get('/api/prefs/:key', c => {
  const k = c.req.param('key')
  return c.json({ prefs: Object.hasOwn(state.data.prefs, k) ? state.data.prefs[k] : null, global: state.data.global })
})

app.put('/api/prefs/:key', async c => {
  const k = c.req.param('key')
  const b = await c.req.json().catch(() => ({}))
  if (k === '_global') state.data.global = { ...state.data.global, ...('size' in b ? { size: clamp(b.size, 10, 40, 17) } : {}) }
  else if (PKEY.test(k)) state.data.prefs[k] = { ...(state.data.prefs[k] || {}), ...cleanPrefs(b) }
  else return c.json({ error: 'Bad key' }, 400)
  state.save()
  return c.json({ ok: true })
})

// ---------- songs ----------
const brief = ({ body, ...s }) => ({ ...s, lastOpened: state.data.opened[s.id]?.last || '', openCount: state.data.opened[s.id]?.count || 0 })

app.get('/api/songs', c => {
  const q = (c.req.query('q') || '').toLowerCase().trim()
  const list = [...songs.values()].filter(s => !q || `${s.title}\n${s.artist}\n${s.tags.join(' ')}\n${s.body}`.toLowerCase().includes(q))
  return c.json(list.map(brief))
})

app.get('/api/songs/:id', c => {
  const s = songs.get(c.req.param('id'))
  return s ? c.json(s) : c.json({ error: 'Song not found' }, 404)
})

app.post('/api/songs/:id/opened', c => {
  const id = c.req.param('id')
  if (!songs.has(id)) return c.json({ error: 'Song not found' }, 404)
  const o = state.data.opened[id] || { count: 0 }
  state.data.opened[id] = { last: new Date().toISOString(), count: o.count + 1 }
  state.save()
  return c.json({ ok: true })
})

function withGuessedKey(d) {
  if (one(d.key)) return { ...d, keyGuessed: false }
  const g = guessKey(d.body, '')
  return g ? { ...d, key: g.key, keyGuessed: true } : { ...d, keyGuessed: false }
}

// Preview an import without writing a file.
app.post('/api/songs/preview', async c => {
  const b = await c.req.json().catch(() => ({}))
  try {
    let d
    if (b.url) {
      const url = String(b.url).trim()
      const dup = [...songs.values()].find(s => s.source === url)
      d = withGuessedKey(await importFromUrl(url))
      d.source = url
      return c.json({
        title: d.title || '', artist: d.artist || '', key: d.key || '', capo: +d.capo || 0,
        body: d.body, source: url, keyGuessed: !!d.keyGuessed, duplicateId: dup?.id || null,
      })
    }
    if (b.text) {
      const parsed = parseSheet(b.text)
      d = withGuessedKey({
        title: b.title, artist: b.artist, key: parsed.key, capo: parsed.capo, body: parsed.body,
      })
      return c.json({
        title: d.title || '', artist: d.artist || '', key: d.key || '', capo: +d.capo || 0,
        body: d.body, source: '', keyGuessed: !!d.keyGuessed, duplicateId: null,
      })
    }
    return c.json({ error: 'Give a link or paste some text.' }, 400)
  } catch (e) {
    return c.json({ error: e.message }, 422)
  }
})

app.post('/api/songs/import', async c => {
  const b = await c.req.json().catch(() => ({}))
  try {
    let d
    if (typeof b.body === 'string' && !b.url) {
      // Confirm-save from a preview: no second fetch.
      const source = one(b.source)
      if (source) {
        const dup = [...songs.values()].find(s => s.source === source)
        if (dup) return c.json({ id: dup.id, duplicate: true })
      }
      const body = b.body.replace(/\r\n/g, '\n')
      d = {
        title: b.title, artist: b.artist, key: b.key, capo: b.capo,
        source, body: body.endsWith('\n') ? body : body + '\n',
      }
    } else if (b.url) {
      const url = String(b.url).trim()
      const dup = [...songs.values()].find(s => s.source === url)
      if (dup) return c.json({ id: dup.id, duplicate: true })
      d = withGuessedKey(await importFromUrl(url))
      d.source = url
    } else if (b.text) {
      const parsed = parseSheet(b.text)
      d = withGuessedKey({
        title: b.title, artist: b.artist, key: parsed.key, capo: parsed.capo,
        source: '', body: parsed.body,
      })
    } else return c.json({ error: 'Give a link or paste some text.' }, 400)
    if (!one(d.title)) throw new Error('No title found. Add a title and try again.')
    const s = {
      id: newId(one(d.artist), one(d.title)), title: one(d.title), artist: one(d.artist), key: one(d.key),
      capo: +d.capo || 0, tags: [], source: d.source || '', added: new Date().toISOString().slice(0, 10), body: d.body,
    }
    await save(s)
    return c.json({ id: s.id })
  } catch (e) {
    return c.json({ error: e.message }, 422)
  }
})

// Fetch the source again and return the result without saving it.
app.post('/api/songs/:id/reimport', async c => {
  const s = songs.get(c.req.param('id'))
  if (!s) return c.json({ error: 'Song not found' }, 404)
  if (!s.source) return c.json({ error: 'This song has no source link.' }, 422)
  try {
    const d = await importFromUrl(s.source)
    return c.json({
      body: d.body, key: d.key || '', capo: +d.capo || 0,
      current: s.body, currentKey: s.key || '', currentCapo: s.capo || 0,
    })
  } catch (e) {
    return c.json({ error: e.message }, 422)
  }
})

// Replace the body (after a re-import preview). Keeps the old version in .history.
app.post('/api/songs/:id/replace', async c => {
  const old = songs.get(c.req.param('id'))
  if (!old) return c.json({ error: 'Song not found' }, 404)
  const b = await c.req.json().catch(() => ({}))
  const body = String(b.body || '').replace(/\r\n/g, '\n').replace(/\s+$/, '')
  if (!body.trim()) return c.json({ error: 'The new version is empty.' }, 400)
  await snapshot(old)
  const s = {
    ...old,
    body: body + '\n',
    ...(b.updateMeta ? { key: one(b.key), capo: +b.capo || 0 } : {}),
  }
  await save(s)
  return c.json(s)
})

app.put('/api/songs/:id', async c => {
  const old = songs.get(c.req.param('id'))
  if (!old) return c.json({ error: 'Song not found' }, 404)
  const b = await c.req.json().catch(() => ({}))
  const tags = Array.isArray(b.tags) ? b.tags : String(b.tags ?? '').split(',')
  const s = {
    ...old, title: one(b.title) || old.title, artist: one(b.artist), key: one(b.key), capo: +b.capo || 0,
    tags: tags.map(one).filter(Boolean), body: String(b.body ?? old.body).replace(/\r\n/g, '\n'),
  }
  await save(s)
  return c.json(s)
})

app.delete('/api/songs/:id', async c => {
  const id = c.req.param('id')
  if (!songs.has(id)) return c.json({ error: 'Song not found' }, 404)
  songs.delete(id)
  await fs.rm(path.join(SONGS_DIR, id + '.txt'), { force: true })
  return c.json({ ok: true })
})

// ---------- setlists and medleys: same shape, saved as JSON in DATA_DIR ----------
function cleanItems(list, withSections) {
  return (Array.isArray(list) ? list : []).slice(0, 200)
    .filter(i => i && typeof i.songId === 'string' && /^[a-z0-9-]+$/.test(i.songId))
    .map(i => {
      const o = { songId: i.songId, t: clamp(i.t, -11, 11), c: clamp(i.c, 0, 11) }
      if (withSections) o.sections = Array.isArray(i.sections) && i.sections.length ? i.sections.slice(0, 40).map(s => String(s).slice(0, 80)) : null
      return o
    })
}

function crud(plural, col, { type, sections, extra }) {
  const base = `/api/${plural}`
  app.get(base, c => c.json(col.all().map(x => ({ id: x.id, name: x.name, count: x.items.length, created: x.created }))))
  app.post(base, async c => {
    const b = await c.req.json().catch(() => ({}))
    const name = one(b.name)
    if (!name) return c.json({ error: 'Give it a name.' }, 400)
    const item = { id: col.newId(name), name, created: new Date().toISOString().slice(0, 10), items: cleanItems(b.items, sections) }
    extra?.(item, b)
    await col.save(item)
    return c.json(item)
  })
  app.get(`${base}/:id`, c => { const x = col.get(c.req.param('id')); return x ? c.json(x) : c.json({ error: 'Not found' }, 404) })
  app.get(`${base}/:id/full`, c => { const b = bundle(type, c.req.param('id')); return b ? c.json(b) : c.json({ error: 'Not found' }, 404) })
  app.put(`${base}/:id`, async c => {
    const old = col.get(c.req.param('id'))
    if (!old) return c.json({ error: 'Not found' }, 404)
    const b = await c.req.json().catch(() => ({}))
    const item = { ...old, name: one(b.name) || old.name, items: 'items' in b ? cleanItems(b.items, sections) : old.items }
    extra?.(item, b)
    await col.save(item)
    return c.json(item)
  })
  app.delete(`${base}/:id`, async c => {
    const id = c.req.param('id')
    if (!col.get(id)) return c.json({ error: 'Not found' }, 404)
    await col.remove(id)
    return c.json({ ok: true })
  })
}
crud('setlists', setlists, { type: 'setlist', sections: false })
crud('medleys', medleys, { type: 'medley', sections: true, extra: (item, b) => { if ('targetKey' in b) item.targetKey = one(b.targetKey).slice(0, 12) } })

// ---------- pages ----------
const page = (route, file) => app.get(route, serveStatic({ path: `./public/${file}` }))
page('/', 'index.html'); page('/song', 'song.html'); page('/setlists', 'setlists.html'); page('/setlist', 'setlist.html'); page('/medley', 'medley.html')
app.use('/*', serveStatic({ root: './public' }))

serve({ fetch: app.fetch, port: PORT, hostname: HOST }, i => console.log(`Chords on http://${HOST}:${i.port}  (${songs.size} songs in ${SONGS_DIR}, data in ${DATA_DIR})`))
