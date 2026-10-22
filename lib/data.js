// Small JSON file helpers and per-user libraries (songs + setlists + medleys + prefs).
import fs from 'node:fs/promises'
import { watch } from 'node:fs'
import path from 'node:path'

export const slug = s => String(s).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80) || 'item'

export async function writeAtomic(file, text) {
  const tmp = `${file}.${process.pid}.tmp`
  await fs.writeFile(tmp, text)
  await fs.rename(tmp, file)
}

export async function readJson(file, fallback) {
  try { return JSON.parse(await fs.readFile(file, 'utf8')) } catch { return fallback }
}

// One JSON document with debounced, atomic saves.
export async function jsonStore(file, fallback) {
  await fs.mkdir(path.dirname(file), { recursive: true })
  const data = await readJson(file, fallback)
  let timer
  const flush = () => { clearTimeout(timer); return writeAtomic(file, JSON.stringify(data)) }
  return { data, flush, save() { clearTimeout(timer); timer = setTimeout(() => flush().catch(console.error), 300) } }
}

// A folder of <id>.json files, all held in memory.
export async function collection(dir) {
  await fs.mkdir(dir, { recursive: true })
  const items = new Map()
  for (const f of await fs.readdir(dir)) {
    if (!f.endsWith('.json')) continue
    const v = await readJson(path.join(dir, f), null)
    if (v?.id) items.set(v.id, v)
  }
  return {
    all: () => [...items.values()],
    get: id => items.get(id),
    newId(name) {
      const base = slug(name)
      let id = base, n = 2
      while (items.has(id)) id = `${base}-${n++}`
      return id
    },
    async save(item) { items.set(item.id, item); await writeAtomic(path.join(dir, item.id + '.json'), JSON.stringify(item, null, 2)) },
    async remove(id) { items.delete(id); await fs.rm(path.join(dir, id + '.json'), { force: true }) },
  }
}

const one = s => String(s ?? '').replace(/[\r\n]+/g, ' ').trim()

function parseSong(id, text) {
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

const serializeSong = s => `---
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

/** Open (or create) a per-user library under root: songs/, state.json, setlists/, medleys/. */
export async function openLibrary(root) {
  const songsDir = path.join(root, 'songs')
  const songs = new Map()

  async function load() {
    const files = (await fs.readdir(songsDir)).filter(f => f.endsWith('.txt'))
    const next = new Map()
    await Promise.all(files.map(async f => next.set(f.slice(0, -4), parseSong(f.slice(0, -4), await fs.readFile(path.join(songsDir, f), 'utf8')))))
    songs.clear()
    for (const [k, v] of next) songs.set(k, v)
  }

  function newId(artist, title) {
    const base = slug(`${artist} ${title}`)
    let id = base, n = 2
    while (songs.has(id)) id = `${base}-${n++}`
    return id
  }

  const save = async s => { songs.set(s.id, s); await fs.writeFile(path.join(songsDir, s.id + '.txt'), serializeSong(s)) }

  async function snapshot(s) {
    const dir = path.join(songsDir, '.history')
    await fs.mkdir(dir, { recursive: true })
    await fs.writeFile(path.join(dir, `${s.id}.${Date.now()}.txt`), serializeSong(s))
    const mine = (await fs.readdir(dir)).filter(f => f.startsWith(s.id + '.') && f.endsWith('.txt')).sort()
    for (const f of mine.slice(0, -5)) await fs.rm(path.join(dir, f), { force: true })
  }

  await fs.mkdir(songsDir, { recursive: true })
  await load()
  let timer
  const watcher = watch(songsDir, () => { clearTimeout(timer); timer = setTimeout(() => load().catch(console.error), 300) })

  const state = await jsonStore(path.join(root, 'state.json'), {})
  for (const k of ['prefs', 'opened', 'global']) state.data[k] ||= {}
  const setlists = await collection(path.join(root, 'setlists'))
  const medleys = await collection(path.join(root, 'medleys'))

  const pub = s => ({ id: s.id, title: s.title, artist: s.artist, key: s.key, capo: s.capo, body: s.body })
  const brief = ({ body, ...s }) => ({
    ...s,
    lastOpened: state.data.opened[s.id]?.last || '',
    openCount: state.data.opened[s.id]?.count || 0,
  })

  function bundle(type, id) {
    if (!['song', 'setlist', 'medley'].includes(type)) return null
    if (type === 'song') { const s = songs.get(id); return s && { type, song: pub(s) } }
    const item = (type === 'setlist' ? setlists : medleys).get(id)
    if (!item) return null
    const map = {}
    for (const it of item.items) { const s = songs.get(it.songId); if (s) map[s.id] = pub(s) }
    return { type, [type]: item, songs: map }
  }

  return {
    root, songsDir, songs, state, setlists, medleys,
    load, save, snapshot, newId, pub, brief, bundle,
    flush: () => state.flush(),
    close: () => { clearTimeout(timer); watcher.close() },
  }
}
