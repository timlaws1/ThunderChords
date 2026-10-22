// Small helpers shared by every page: fetch wrapper, escaping, offline handling, service worker.
export const $ = s => document.querySelector(s)
export const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))
export const store = {
  get: k => { try { return localStorage.getItem(k) } catch { return null } },
  set: (k, v) => { try { localStorage.setItem(k, v) } catch {} },
}

export async function api(url, { method = 'GET', body } = {}) {
  let r
  try {
    r = await fetch(url, { method, ...(body !== undefined && { headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) }) })
  } catch {
    throw Object.assign(new Error('You are offline or the server cannot be reached.'), { offline: true })
  }
  if (r.status === 401 && !location.pathname.startsWith('/s/')) location = '/login'
  const j = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(j.error || r.statusText)
  return j
}

// Settings changes made offline wait here and are sent when the connection returns.
const readQueue = () => { try { return JSON.parse(store.get('chords:queue')) || {} } catch { return {} } }
export function queuePrefs(key, prefs) {
  const q = readQueue()
  q[key] = { ...q[key], ...prefs }
  store.set('chords:queue', JSON.stringify(q))
}
export async function flushQueue() {
  const q = readQueue()
  if (!Object.keys(q).length || !navigator.onLine) return
  for (const k of Object.keys(q)) {
    try { await api('/api/prefs/' + encodeURIComponent(k), { method: 'PUT', body: q[k] }); delete q[k] } catch (e) { if (e.offline) break }
  }
  store.set('chords:queue', JSON.stringify(q))
}

// Buttons marked data-needs-net are switched off while offline.
export function applyNet() {
  for (const b of document.querySelectorAll('[data-needs-net]')) {
    b.disabled = !navigator.onLine
    if (!navigator.onLine) b.title = 'Not available offline'
    else if (b.title === 'Not available offline') b.removeAttribute('title')
  }
}

function netState() {
  let bar = document.getElementById('offline')
  if (!navigator.onLine && !bar) {
    bar = document.createElement('div')
    bar.id = 'offline'
    bar.className = 'noprint'
    bar.textContent = 'You are offline. Saved songs still work. Editing, importing and sharing are switched off.'
    document.body.prepend(bar)
  } else if (navigator.onLine) bar?.remove()
  applyNet()
}
addEventListener('online', () => { netState(); flushQueue() })
addEventListener('offline', netState)
netState()
flushQueue()

if ('serviceWorker' in navigator && !location.pathname.startsWith('/s/')) navigator.serviceWorker.register('/sw.js').catch(() => {})
