import { serve } from '@hono/node-server'
import { serveStatic } from '@hono/node-server/serve-static'
import { Hono } from 'hono'
import { getCookie, setCookie, deleteCookie } from 'hono/cookie'
import { createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { openLibrary, jsonStore } from './lib/data.js'
import { openUsers, signSession, verifySession, verifyPassword, migrateOwnerLibrary, libraryRoot } from './lib/auth.js'
import { importFromUrl, parseSheet } from './importer.js'
import { guessKey } from './public/music.js'

process.chdir(path.dirname(fileURLToPath(import.meta.url)))

const PORT = +process.env.PORT || 3000
const HOST = process.env.HOST || '127.0.0.1'
const PASSWORD = process.env.PASSWORD
const OWNER_EMAIL = (process.env.OWNER_EMAIL || '').trim().toLowerCase()
const INVITE_CODE = process.env.INVITE_CODE || ''
const SONGS_DIR = path.resolve(process.env.SONGS_DIR || './songs')
const DATA_DIR = path.resolve(process.env.DATA_DIR || (process.env.SONGS_DIR ? path.join(SONGS_DIR, '..') : './data'))

const SECRET = process.env.SECRET || (PASSWORD ? createHash('sha256').update('chords:' + PASSWORD).digest('hex') : '')
if (!SECRET) {
  console.error('Set SECRET (and for first boot: OWNER_EMAIL, PASSWORD). Optional: PORT, HOST, SONGS_DIR, DATA_DIR, INVITE_CODE.')
  process.exit(1)
}

const one = s => String(s ?? '').replace(/[\r\n]+/g, ' ').trim()
const clamp = (v, lo, hi, d = 0) => { const n = Math.round(+v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : d }
const same = (a, b) => {
  const x = Buffer.from(String(a)), y = Buffer.from(String(b))
  if (x.length !== y.length) return false
  return timingSafeEqual(x, y)
}

await fs.mkdir(DATA_DIR, { recursive: true })
const users = await openUsers(path.join(DATA_DIR, 'users.json'))

// First boot: create the owner and move the legacy single-library folders.
if (!users.all().length) {
  if (!PASSWORD || !OWNER_EMAIL) {
    console.error('First boot needs OWNER_EMAIL and PASSWORD to create the owner account.')
    process.exit(1)
  }
  const owner = await users.create({ email: OWNER_EMAIL, password: PASSWORD, name: 'Owner' })
  await migrateOwnerLibrary({ dataDir: DATA_DIR, songsDir: SONGS_DIR, ownerId: owner.id })
  console.log(`Created owner ${owner.email} and migrated library to ${libraryRoot(DATA_DIR, owner.id)}`)
}

const shares = await jsonStore(path.join(DATA_DIR, 'shares.json'), {})
const libraries = new Map()

async function libFor(userId) {
  if (!libraries.has(userId)) {
    const p = openLibrary(libraryRoot(DATA_DIR, userId))
    libraries.set(userId, p)
    p.catch(() => libraries.delete(userId))
  }
  return libraries.get(userId)
}

for (const sig of ['SIGINT', 'SIGTERM']) {
  process.on(sig, async () => {
    await Promise.all([
      users.flush(),
      shares.flush(),
      ...[...libraries.values()].map(p => p.then(l => l.flush()).catch(() => {})),
    ])
    process.exit(0)
  })
}

function withGuessedKey(d) {
  if (one(d.key)) return { ...d, keyGuessed: false }
  const g = guessKey(d.body, '')
  return g ? { ...d, key: g.key, keyGuessed: true } : { ...d, keyGuessed: false }
}

function cookieOpts(c) {
  return {
    httpOnly: true,
    sameSite: 'Lax',
    path: '/',
    maxAge: 60 * 60 * 24 * 365,
    secure: c.req.header('x-forwarded-proto') === 'https',
  }
}

const shell = (title, body) => `<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>${title}</title>
<link rel="icon" href="/icon.svg">
<meta name="theme-color" content="#12171f">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=Syne:600,700|DM+Sans:400,500,600|IBM+Plex+Mono:400,700" rel="stylesheet">
<script src="/theme.js"></script>
<link rel="stylesheet" href="/style.css">
</head>
<body class="login">
<button type="button" class="theme-btn btn theme-corner" data-theme-toggle>Dark</button>
${body}
</body></html>`

const loginPage = (msg = '') => shell('ThunderChords', `
<form class="login-card rounded-xl border border-line bg-panel p-6 shadow-lg" method="post" action="/login">
  <h1 class="brand">ThunderChords</h1>
  <p class="muted">Sign in to your chord library</p>
  <input type="email" name="email" placeholder="Email" autocomplete="username" autofocus required>
  <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
  <button class="primary">Sign in</button>
  <p class="err">${msg}</p>
  ${INVITE_CODE ? '<p class="muted"><a class="underline decoration-chord/40 hover:decoration-chord" href="/signup">Create an account</a></p>' : ''}
</form>`)

const signupPage = (msg = '') => shell('Sign up · ThunderChords', `
<form class="login-card rounded-xl border border-line bg-panel p-6 shadow-lg" method="post" action="/signup">
  <h1 class="brand">ThunderChords</h1>
  <p class="muted">Create an account with an invite code</p>
  <input type="email" name="email" placeholder="Email" autocomplete="username" autofocus required>
  <input type="text" name="name" placeholder="Name (optional)" autocomplete="name">
  <input type="password" name="password" placeholder="Password (8+ characters)" autocomplete="new-password" required minlength="8">
  <input type="password" name="invite" placeholder="Invite code" autocomplete="off" required>
  <button class="primary">Create account</button>
  <p class="err">${msg}</p>
  <p class="muted"><a class="underline decoration-chord/40 hover:decoration-chord" href="/login">Already have an account?</a></p>
</form>`)

const accountPage = (user, msg = '', ok = '') => shell('Account · ThunderChords', `
<form class="login-card rounded-xl border border-line bg-panel p-6 shadow-lg" method="post" action="/account/password">
  <h1 class="brand">Account</h1>
  <p class="muted">${one(user.email)}</p>
  <input type="password" name="current" placeholder="Current password" autocomplete="current-password" required>
  <input type="password" name="password" placeholder="New password (8+ characters)" autocomplete="new-password" required minlength="8">
  <button class="primary">Change password</button>
  <p class="err">${msg}</p>
  <p class="muted">${ok}</p>
  <p class="muted"><a class="underline decoration-chord/40 hover:decoration-chord" href="/">Back to library</a></p>
</form>
<form method="post" action="/logout" class="mt-4 text-center">
  <button type="submit" class="btn">Sign out</button>
</form>`)

const app = new Hono()
app.use('*', async (c, next) => { await next(); c.header('X-Robots-Tag', 'noindex') })

app.get('/login', c => c.html(loginPage()))
app.post('/login', async c => {
  const body = await c.req.parseBody()
  const user = users.findByEmail(body.email)
  if (user && await verifyPassword(body.password || '', user.passwordHash)) {
    setCookie(c, 'sid', signSession(SECRET, user.id), cookieOpts(c))
    return c.redirect('/')
  }
  await new Promise(r => setTimeout(r, 800))
  return c.html(loginPage('Wrong email or password.'), 401)
})

app.get('/signup', c => {
  if (!INVITE_CODE) return c.html(shell('Sign up · ThunderChords', '<div class="login-card rounded-xl border border-line bg-panel p-6 shadow-lg"><h1 class="brand">ThunderChords</h1><p class="muted">Signup is closed. Ask the host for an invite.</p><p class="muted"><a class="underline" href="/login">Sign in</a></p></div>'), 403)
  return c.html(signupPage())
})
app.post('/signup', async c => {
  if (!INVITE_CODE) return c.html(signupPage('Signup is closed.'), 403)
  const body = await c.req.parseBody()
  if (!same(String(body.invite || ''), INVITE_CODE)) {
    await new Promise(r => setTimeout(r, 800))
    return c.html(signupPage('Wrong invite code.'), 403)
  }
  try {
    const user = await users.create({ email: body.email, password: body.password, name: body.name })
    await libFor(user.id)
    setCookie(c, 'sid', signSession(SECRET, user.id), cookieOpts(c))
    return c.redirect('/')
  } catch (e) {
    return c.html(signupPage(e.message), 400)
  }
})

app.post('/logout', c => { deleteCookie(c, 'sid', { path: '/' }); return c.redirect('/login') })

// Files a shared page needs. Code only, no data.
const PUBLIC = new Set([
  '/login', '/signup', '/style.css', '/theme.js', '/chords.js', '/music.js', '/sheet.js', '/scroll.js',
  '/viewer.js', '/song.js', '/api.js', '/manifest.webmanifest', '/sw.js', '/icon.svg',
  '/icon-192.png', '/icon-512.png', '/apple-touch-icon.png',
])

app.use('*', async (c, next) => {
  const p = c.req.path
  if (PUBLIC.has(p) || p.startsWith('/s/') || p.startsWith('/api/shared/')) return next()
  const sid = getCookie(c, 'sid')
  const userId = verifySession(SECRET, sid)
  const user = userId && users.get(userId)
  if (!user) {
    return p.startsWith('/api/') ? c.json({ error: 'Not signed in' }, 401) : c.redirect('/login')
  }
  c.set('user', user)
  c.set('lib', await libFor(user.id))
  return next()
})

app.get('/account', c => c.html(accountPage(c.get('user'))))
app.post('/account/password', async c => {
  const user = c.get('user')
  const body = await c.req.parseBody()
  if (!(await verifyPassword(body.current || '', user.passwordHash))) {
    await new Promise(r => setTimeout(r, 800))
    return c.html(accountPage(user, 'Current password is wrong.'), 401)
  }
  try {
    await users.setPassword(user.id, body.password)
    return c.html(accountPage(users.get(user.id), '', 'Password updated.'))
  } catch (e) {
    return c.html(accountPage(user, e.message), 400)
  }
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

async function shareFor(token) {
  if (!/^[A-Za-z0-9_-]{16,40}$/.test(token) || !Object.hasOwn(shares.data, token)) return null
  const s = shares.data[token]
  if (s.expires && Date.parse(s.expires) < Date.now()) { delete shares.data[token]; shares.save(); return null }
  if (!s.userId || !users.get(s.userId)) return null
  return s
}

app.get('/s/:token', async (c, next) => {
  c.header('Cache-Control', 'no-store')
  if (limited(c)) return c.text('Too many requests', 429)
  if (!(await shareFor(c.req.param('token')))) return c.text('This link has expired or was removed.', 404)
  return serveStatic({ path: './public/song.html' })(c, next)
})

app.get('/api/shared/:token', async c => {
  c.header('Cache-Control', 'no-store')
  if (limited(c)) return c.json({ error: 'Too many requests' }, 429)
  const s = await shareFor(c.req.param('token'))
  if (!s) return c.json({ error: 'This link has expired or was removed.' }, 404)
  const lib = await libFor(s.userId)
  const b = lib.bundle(s.type, s.id)
  return b ? c.json(b) : c.json({ error: 'This link has expired or was removed.' }, 404)
})

const DAYS = { '7d': 7, '30d': 30, never: 0 }

app.post('/api/shares', async c => {
  const lib = c.get('lib')
  const user = c.get('user')
  const b = await c.req.json().catch(() => ({}))
  if (!lib.bundle(b.type, b.id)) return c.json({ error: 'Nothing to share.' }, 404)
  const days = Object.hasOwn(DAYS, b.expires) ? DAYS[b.expires] : 30
  const token = randomBytes(16).toString('base64url')
  const rec = {
    userId: user.id, type: b.type, id: b.id,
    created: new Date().toISOString(),
    expires: days ? new Date(Date.now() + days * 864e5).toISOString() : null,
  }
  shares.data[token] = rec
  shares.save()
  return c.json({ token, url: '/s/' + token, ...rec })
})

app.get('/api/shares', async c => {
  const user = c.get('user')
  const { type, id } = c.req.query()
  const out = []
  for (const t of Object.keys(shares.data)) {
    const s = await shareFor(t)
    if (s && s.userId === user.id && s.type === type && s.id === id) out.push({ token: t, url: '/s/' + t, ...s })
  }
  return c.json(out)
})

app.delete('/api/shares/:token', async c => {
  const user = c.get('user')
  const t = c.req.param('token')
  const s = shares.data[t]
  if (!s || s.userId !== user.id) return c.json({ error: 'Link not found' }, 404)
  delete shares.data[t]
  shares.save()
  return c.json({ ok: true })
})

app.get('/api/me', c => {
  const u = c.get('user')
  return c.json({ id: u.id, email: u.email, name: u.name })
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
  const lib = c.get('lib')
  const k = c.req.param('key')
  return c.json({ prefs: Object.hasOwn(lib.state.data.prefs, k) ? lib.state.data.prefs[k] : null, global: lib.state.data.global })
})

app.put('/api/prefs/:key', async c => {
  const lib = c.get('lib')
  const k = c.req.param('key')
  const b = await c.req.json().catch(() => ({}))
  if (k === '_global') lib.state.data.global = { ...lib.state.data.global, ...('size' in b ? { size: clamp(b.size, 10, 40, 17) } : {}) }
  else if (PKEY.test(k)) lib.state.data.prefs[k] = { ...(lib.state.data.prefs[k] || {}), ...cleanPrefs(b) }
  else return c.json({ error: 'Bad key' }, 400)
  lib.state.save()
  return c.json({ ok: true })
})

// ---------- songs ----------
app.get('/api/songs', c => {
  const lib = c.get('lib')
  const q = (c.req.query('q') || '').toLowerCase().trim()
  const list = [...lib.songs.values()].filter(s => !q || `${s.title}\n${s.artist}\n${s.tags.join(' ')}\n${s.body}`.toLowerCase().includes(q))
  return c.json(list.map(s => lib.brief(s)))
})

app.get('/api/songs/:id', c => {
  const s = c.get('lib').songs.get(c.req.param('id'))
  return s ? c.json(s) : c.json({ error: 'Song not found' }, 404)
})

app.post('/api/songs/:id/opened', c => {
  const lib = c.get('lib')
  const id = c.req.param('id')
  if (!lib.songs.has(id)) return c.json({ error: 'Song not found' }, 404)
  const o = lib.state.data.opened[id] || { count: 0 }
  lib.state.data.opened[id] = { last: new Date().toISOString(), count: o.count + 1 }
  lib.state.save()
  return c.json({ ok: true })
})

app.post('/api/songs/preview', async c => {
  const lib = c.get('lib')
  const b = await c.req.json().catch(() => ({}))
  try {
    let d
    if (b.url) {
      const url = String(b.url).trim()
      const dup = [...lib.songs.values()].find(s => s.source === url)
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
  const lib = c.get('lib')
  const b = await c.req.json().catch(() => ({}))
  try {
    let d
    if (typeof b.body === 'string' && !b.url) {
      const source = one(b.source)
      if (source) {
        const dup = [...lib.songs.values()].find(s => s.source === source)
        if (dup) return c.json({ id: dup.id, duplicate: true })
      }
      const body = b.body.replace(/\r\n/g, '\n')
      d = {
        title: b.title, artist: b.artist, key: b.key, capo: b.capo,
        source, body: body.endsWith('\n') ? body : body + '\n',
      }
    } else if (b.url) {
      const url = String(b.url).trim()
      const dup = [...lib.songs.values()].find(s => s.source === url)
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
      id: lib.newId(one(d.artist), one(d.title)), title: one(d.title), artist: one(d.artist), key: one(d.key),
      capo: +d.capo || 0, tags: [], source: d.source || '', added: new Date().toISOString().slice(0, 10), body: d.body,
    }
    await lib.save(s)
    return c.json({ id: s.id })
  } catch (e) {
    return c.json({ error: e.message }, 422)
  }
})

app.post('/api/songs/:id/reimport', async c => {
  const lib = c.get('lib')
  const s = lib.songs.get(c.req.param('id'))
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

app.post('/api/songs/:id/replace', async c => {
  const lib = c.get('lib')
  const old = lib.songs.get(c.req.param('id'))
  if (!old) return c.json({ error: 'Song not found' }, 404)
  const b = await c.req.json().catch(() => ({}))
  const body = String(b.body || '').replace(/\r\n/g, '\n').replace(/\s+$/, '')
  if (!body.trim()) return c.json({ error: 'The new version is empty.' }, 400)
  await lib.snapshot(old)
  const s = {
    ...old,
    body: body + '\n',
    ...(b.updateMeta ? { key: one(b.key), capo: +b.capo || 0 } : {}),
  }
  await lib.save(s)
  return c.json(s)
})

app.put('/api/songs/:id', async c => {
  const lib = c.get('lib')
  const old = lib.songs.get(c.req.param('id'))
  if (!old) return c.json({ error: 'Song not found' }, 404)
  const b = await c.req.json().catch(() => ({}))
  const tags = Array.isArray(b.tags) ? b.tags : String(b.tags ?? '').split(',')
  const s = {
    ...old, title: one(b.title) || old.title, artist: one(b.artist), key: one(b.key), capo: +b.capo || 0,
    tags: tags.map(one).filter(Boolean), body: String(b.body ?? old.body).replace(/\r\n/g, '\n'),
  }
  await lib.save(s)
  return c.json(s)
})

app.delete('/api/songs/:id', async c => {
  const lib = c.get('lib')
  const id = c.req.param('id')
  if (!lib.songs.has(id)) return c.json({ error: 'Song not found' }, 404)
  lib.songs.delete(id)
  await fs.rm(path.join(lib.songsDir, id + '.txt'), { force: true })
  return c.json({ ok: true })
})

// ---------- setlists and medleys ----------
function cleanItems(list, withSections) {
  return (Array.isArray(list) ? list : []).slice(0, 200)
    .filter(i => i && typeof i.songId === 'string' && /^[a-z0-9-]+$/.test(i.songId))
    .map(i => {
      const o = { songId: i.songId, t: clamp(i.t, -11, 11), c: clamp(i.c, 0, 11) }
      if (withSections) {
        const custom = typeof i.body === 'string' ? i.body.replace(/\r\n/g, '\n').replace(/\s+$/, '') : ''
        if (custom) {
          o.body = custom.slice(0, 100000) + '\n'
          o.sections = null
        } else {
          o.sections = Array.isArray(i.sections) && i.sections.length ? i.sections.slice(0, 40).map(s => String(s).slice(0, 80)) : null
        }
      }
      return o
    })
}

function crud(plural, pick, { type, sections, extra }) {
  const base = `/api/${plural}`
  app.get(base, c => c.json(pick(c).all().map(x => ({ id: x.id, name: x.name, count: x.items.length, created: x.created }))))
  app.post(base, async c => {
    const col = pick(c)
    const b = await c.req.json().catch(() => ({}))
    const name = one(b.name)
    if (!name) return c.json({ error: 'Give it a name.' }, 400)
    const item = { id: col.newId(name), name, created: new Date().toISOString().slice(0, 10), items: cleanItems(b.items, sections) }
    extra?.(item, b)
    await col.save(item)
    return c.json(item)
  })
  app.get(`${base}/:id`, c => { const x = pick(c).get(c.req.param('id')); return x ? c.json(x) : c.json({ error: 'Not found' }, 404) })
  app.get(`${base}/:id/full`, c => { const b = c.get('lib').bundle(type, c.req.param('id')); return b ? c.json(b) : c.json({ error: 'Not found' }, 404) })
  app.put(`${base}/:id`, async c => {
    const col = pick(c)
    const old = col.get(c.req.param('id'))
    if (!old) return c.json({ error: 'Not found' }, 404)
    const b = await c.req.json().catch(() => ({}))
    const item = { ...old, name: one(b.name) || old.name, items: 'items' in b ? cleanItems(b.items, sections) : old.items }
    extra?.(item, b)
    await col.save(item)
    return c.json(item)
  })
  app.delete(`${base}/:id`, async c => {
    const col = pick(c)
    const id = c.req.param('id')
    if (!col.get(id)) return c.json({ error: 'Not found' }, 404)
    await col.remove(id)
    return c.json({ ok: true })
  })
}
crud('setlists', c => c.get('lib').setlists, { type: 'setlist', sections: false })
crud('medleys', c => c.get('lib').medleys, { type: 'medley', sections: true, extra: (item, b) => { if ('targetKey' in b) item.targetKey = one(b.targetKey).slice(0, 12) } })

// ---------- pages ----------
const page = (route, file) => app.get(route, serveStatic({ path: `./public/${file}` }))
page('/', 'index.html'); page('/song', 'song.html'); page('/setlists', 'setlists.html'); page('/setlist', 'setlist.html'); page('/medley', 'medley.html')
app.use('/*', serveStatic({ root: './public' }))

const owner = users.all()[0]
serve({ fetch: app.fetch, port: PORT, hostname: HOST }, async i => {
  const lib = await libFor(owner.id)
  console.log(`ThunderChords on http://${HOST}:${i.port}  (${users.all().length} users, owner library ${lib.songs.size} songs in ${lib.songsDir})`)
})
