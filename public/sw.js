// Service worker: keeps the app and your songs available without a connection.
// Bump V when you change this file or want everyone to drop old caches.
const V = 'thunderchords-laravel-v1'

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', e => e.waitUntil(
  caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim())
))

// Only cache good, direct responses. Never a redirect to the login page.
const cacheable = r => r && r.ok && !r.redirected && r.type === 'basic' && !r.url.endsWith('/login') && !r.url.endsWith('/register')

async function networkFirst(req, key) {
  const cache = await caches.open(V)
  try {
    const res = await fetch(req)
    if (cacheable(res)) cache.put(key, res.clone())
    return res
  } catch {
    return (await cache.match(key)) || Response.error()
  }
}

async function staleWhileRevalidate(e, req, key) {
  const cache = await caches.open(V)
  const hit = await cache.match(key)
  const fresh = fetch(req).then(res => { if (cacheable(res)) cache.put(key, res.clone()); return res }).catch(() => null)
  e.waitUntil(fresh)
  return hit || (await fresh) || Response.error()
}

// Offline search: filter the cached song list by title, artist and tags.
async function offlineSearch(url) {
  const cache = await caches.open(V)
  const hit = await cache.match('/api/songs')
  if (!hit) return Response.error()
  const q = (url.searchParams.get('q') || '').toLowerCase()
  const list = (await hit.json()).filter(s => !q || `${s.title} ${s.artist} ${(s.tags || []).join(' ')}`.toLowerCase().includes(q))
  return Response.json(list)
}

self.addEventListener('fetch', e => {
  const req = e.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== location.origin) return
  const p = url.pathname
  if (p === '/login' || p === '/register' || p.startsWith('/s/') || p.startsWith('/api/shared/') || p.startsWith('/api/shares') || p === '/logout') return

  if (p.startsWith('/api/')) {
    if (p === '/api/songs' && url.searchParams.get('q')) {
      e.respondWith(fetch(req).catch(() => offlineSearch(url)))
      return
    }
    e.respondWith(networkFirst(req, p + url.search))
    return
  }
  // Vite assets and pages: serve the saved copy at once and refresh in the background.
  e.respondWith(staleWhileRevalidate(e, req, p + url.search))
})
