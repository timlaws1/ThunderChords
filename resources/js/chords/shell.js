// Shared app-shell behaviour: mobile nav drawer, global search, sidebar setlists.
import { api, esc, $ } from './api.js'

export function setNavOpen(open) {
  const shell = document.querySelector('.app')
  const toggle = $('#nav-toggle')
  const scrim = $('#dash-scrim')
  if (!shell) return
  shell.classList.toggle('open', open)
  if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false')
  if (scrim) scrim.hidden = !open
}

const toggle = $('#nav-toggle')
const close = $('#nav-close')
const scrim = $('#dash-scrim')
if (toggle) toggle.addEventListener('click', () => setNavOpen(true))
if (close) close.addEventListener('click', () => setNavOpen(false))
if (scrim) scrim.addEventListener('click', () => setNavOpen(false))

const search = $('#q')
if (search && search.dataset.searchLive !== '1') {
  search.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    const q = search.value.trim()
    location = q ? '/?q=' + encodeURIComponent(q) : '/'
  })
}

async function loadSetlistsNav() {
  const el = $('#setlists-nav')
  if (!el || el.dataset.owned === '1') return
  try {
    const setlists = await api('/api/setlists')
    if (!setlists.length) {
      el.innerHTML = `<a class="set-link" href="/setlists"><span>No setlists yet</span></a>`
      return
    }
    el.innerHTML = setlists.slice(0, 8).map(s =>
      `<a class="set-link" href="/setlist?id=${encodeURIComponent(s.id)}"><span class="ico" aria-hidden="true">♧</span><span>${esc(s.name)}</span><span class="count">${s.count ?? 0}</span></a>`
    ).join('')
  } catch {
    el.innerHTML = `<a class="set-link" href="/setlists"><span>Setlists</span></a>`
  }
}

loadSetlistsNav()
