// Users, password hashing, and signed session cookies.
import { createHmac, randomBytes, scrypt, timingSafeEqual } from 'node:crypto'
import { promisify } from 'node:util'
import fs from 'node:fs/promises'
import path from 'node:path'
import { jsonStore, slug, writeAtomic, readJson } from './data.js'

const scryptAsync = promisify(scrypt)

const SCRYPT = { N: 16384, r: 8, p: 1, maxmem: 64 * 1024 * 1024 }
const HASH_LEN = 64

export async function hashPassword(password) {
  const salt = randomBytes(16)
  const hash = await scryptAsync(String(password), salt, HASH_LEN, SCRYPT)
  return `scrypt$${salt.toString('base64url')}$${Buffer.from(hash).toString('base64url')}`
}

export async function verifyPassword(password, stored) {
  const [algo, saltB64, hashB64] = String(stored || '').split('$')
  if (algo !== 'scrypt' || !saltB64 || !hashB64) return false
  const salt = Buffer.from(saltB64, 'base64url')
  const expected = Buffer.from(hashB64, 'base64url')
  const actual = await scryptAsync(String(password), salt, expected.length, SCRYPT)
  return timingSafeEqual(expected, Buffer.from(actual))
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64url')
}

export function signSession(secret, userId, maxAgeSec = 60 * 60 * 24 * 365) {
  const exp = Math.floor(Date.now() / 1000) + maxAgeSec
  const payload = `${userId}.${exp}`
  const sig = createHmac('sha256', secret).update(payload).digest('base64url')
  return `${b64url(payload)}.${sig}`
}

export function verifySession(secret, token) {
  if (!token || typeof token !== 'string') return null
  const i = token.lastIndexOf('.')
  if (i < 1) return null
  const payloadB64 = token.slice(0, i)
  const sig = token.slice(i + 1)
  let payload
  try { payload = Buffer.from(payloadB64, 'base64url').toString() } catch { return null }
  const expect = createHmac('sha256', secret).update(payload).digest('base64url')
  const a = Buffer.from(sig)
  const b = Buffer.from(expect)
  if (a.length !== b.length || !timingSafeEqual(a, b)) return null
  const [userId, expStr] = payload.split('.')
  const exp = +expStr
  if (!userId || !Number.isFinite(exp) || exp * 1000 < Date.now()) return null
  return userId
}

function normEmail(email) {
  return String(email || '').trim().toLowerCase()
}

export async function openUsers(file) {
  const store = await jsonStore(file, { users: [] })
  if (!Array.isArray(store.data.users)) store.data.users = []

  const byId = () => {
    const m = new Map()
    for (const u of store.data.users) m.set(u.id, u)
    return m
  }
  const byEmail = () => {
    const m = new Map()
    for (const u of store.data.users) m.set(u.email, u)
    return m
  }

  return {
    store,
    all: () => store.data.users,
    get: id => byId().get(id),
    findByEmail: email => byEmail().get(normEmail(email)),
    async create({ email, password, name }) {
      const e = normEmail(email)
      if (!e || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) throw new Error('Enter a valid email address.')
      if (String(password || '').length < 8) throw new Error('Password must be at least 8 characters.')
      if (byEmail().has(e)) throw new Error('An account with that email already exists.')
      let id = slug(e.split('@')[0])
      let n = 2
      const ids = byId()
      while (ids.has(id)) id = `${slug(e.split('@')[0])}-${n++}`
      const user = {
        id,
        email: e,
        name: String(name || '').trim().slice(0, 80) || e.split('@')[0],
        passwordHash: await hashPassword(password),
        created: new Date().toISOString().slice(0, 10),
      }
      store.data.users.push(user)
      store.save()
      await store.flush()
      return user
    },
    async setPassword(userId, password) {
      if (String(password || '').length < 8) throw new Error('Password must be at least 8 characters.')
      const u = byId().get(userId)
      if (!u) throw new Error('User not found.')
      u.passwordHash = await hashPassword(password)
      store.save()
      await store.flush()
    },
    flush: () => store.flush(),
  }
}

/** Move a legacy single-library layout into data/users/<ownerId>/. */
export async function migrateOwnerLibrary({ dataDir, songsDir, ownerId }) {
  const dest = path.join(dataDir, 'users', ownerId)
  await fs.mkdir(path.join(dest, 'songs'), { recursive: true })
  await fs.mkdir(path.join(dest, 'setlists'), { recursive: true })
  await fs.mkdir(path.join(dest, 'medleys'), { recursive: true })

  const moveFile = async (from, to) => {
    try {
      await fs.rename(from, to)
    } catch (e) {
      if (e.code === 'ENOENT') return
      await fs.copyFile(from, to)
      await fs.rm(from, { force: true })
    }
  }
  const moveDirContents = async (from, to) => {
    let d
    try { d = await fs.readdir(from, { withFileTypes: true }) } catch { return }
    await fs.mkdir(to, { recursive: true })
    for (const ent of d) {
      const src = path.join(from, ent.name)
      const dst = path.join(to, ent.name)
      if (ent.isDirectory()) await moveDirContents(src, dst)
      else await moveFile(src, dst)
    }
  }

  // Songs: prefer SONGS_DIR; also pick up songs sitting under DATA_DIR/songs.
  // Leave placeholders like .gitkeep behind so a fresh repo still has songs/.
  const moveSongs = async from => {
    let d
    try { d = await fs.readdir(from, { withFileTypes: true }) } catch { return }
    const to = path.join(dest, 'songs')
    await fs.mkdir(to, { recursive: true })
    for (const ent of d) {
      if (ent.name === '.gitkeep') continue
      const src = path.join(from, ent.name)
      const dst = path.join(to, ent.name)
      if (ent.isDirectory()) await moveDirContents(src, dst)
      else await moveFile(src, dst)
    }
  }
  await moveSongs(songsDir)
  if (path.resolve(songsDir) !== path.resolve(path.join(dataDir, 'songs'))) {
    await moveSongs(path.join(dataDir, 'songs'))
  }

  await moveFile(path.join(dataDir, 'state.json'), path.join(dest, 'state.json'))
  await moveDirContents(path.join(dataDir, 'setlists'), path.join(dest, 'setlists'))
  await moveDirContents(path.join(dataDir, 'medleys'), path.join(dest, 'medleys'))

  // Stamp userId on legacy share records.
  const sharesPath = path.join(dataDir, 'shares.json')
  const shares = await readJson(sharesPath, {})
  let changed = false
  for (const token of Object.keys(shares)) {
    if (shares[token] && !shares[token].userId) {
      shares[token].userId = ownerId
      changed = true
    }
  }
  if (changed || !(await fs.stat(sharesPath).then(() => true).catch(() => false))) {
    await writeAtomic(sharesPath, JSON.stringify(shares))
  }

  return dest
}

export function libraryRoot(dataDir, userId) {
  return path.join(dataDir, 'users', userId)
}
