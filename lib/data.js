// Small JSON file helpers for everything that is not a song.
import fs from 'node:fs/promises'
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
