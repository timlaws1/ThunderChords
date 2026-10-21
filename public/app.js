import { api, esc, store, applyNet, $ } from './api.js'

let songs = [], medleys = [], tag = null
const picked = new Set()
$('#sort').value = store.get('sort') || 'artist'
$('#kind').value = store.get('kind') || 'all'

const byTitle = (a, b) => a.title.localeCompare(b.title)
const sorters = {
  artist: (a, b) => a.artist.localeCompare(b.artist) || byTitle(a, b),
  title: byTitle,
  added: (a, b) => b.added.localeCompare(a.added) || byTitle(a, b),
  opened: (a, b) => (b.lastOpened || '').localeCompare(a.lastOpened || '') || byTitle(a, b),
  key: (a, b) => (a.key || '~').localeCompare(b.key || '~') || byTitle(a, b),
}

function render() {
  const kind = $('#kind').value, sort = $('#sort').value
  const tags = [...new Set(songs.flatMap(s => s.tags))].sort()
  if (tag && !tags.includes(tag)) tag = null
  $('#tags').innerHTML = tags.map(t => `<button class="chip${t === tag ? ' on' : ''}" data-t="${esc(t)}">${esc(t)}</button>`).join('')

  const q = $('#q').value.toLowerCase().trim()
  const med = medleys.filter(m => !q || m.name.toLowerCase().includes(q)).map(m => ({ id: m.id, title: m.name, artist: `Medley, ${m.count} songs`, key: '', tags: [], added: m.created || '', lastOpened: '', medley: true }))
  let rows = [
    ...(kind === 'medley' ? [] : songs.filter(s => !tag || s.tags.includes(tag))),
    ...(kind === 'song' || tag ? [] : med),
  ].sort(sorters[sort])

  $('#list').innerHTML = rows.map(s => {
    const date = sort === 'opened' && s.lastOpened ? `<span class="a">Opened ${new Date(s.lastOpened).toLocaleDateString()}</span>` : ''
    const href = s.medley ? `/song?medley=${encodeURIComponent(s.id)}` : `/song?id=${encodeURIComponent(s.id)}`
    const box = s.medley ? '<span></span>' : `<input type="checkbox" class="pick" data-id="${esc(s.id)}" aria-label="Select ${esc(s.title)}"${picked.has(s.id) ? ' checked' : ''}>`
    return `<li>${box}<a href="${href}"><span class="t">${esc(s.title)}</span><span class="a">${esc(s.artist)}</span>${date}${s.key ? `<span class="k">${esc(s.key)}</span>` : s.medley ? '<span class="k">Medley</span>' : ''}</a></li>`
  }).join('')
  $('#empty').hidden = rows.length > 0
  $('#selbar').hidden = picked.size === 0
  $('#selcount').textContent = `${picked.size} selected`
  $('#mk').disabled = picked.size < 2
}

async function load() {
  const [s, m] = await Promise.all([
    api('/api/songs?q=' + encodeURIComponent($('#q').value)),
    api('/api/medleys').catch(() => []),
  ])
  songs = s; medleys = m
  render()
}

let t
$('#q').oninput = () => { clearTimeout(t); t = setTimeout(load, 200) }
$('#sort').onchange = () => { store.set('sort', $('#sort').value); render() }
$('#kind').onchange = () => { store.set('kind', $('#kind').value); render() }
$('#tags').onclick = e => { const b = e.target.closest('[data-t]'); if (!b) return; tag = tag === b.dataset.t ? null : b.dataset.t; render() }
$('#list').onchange = e => { if (!e.target.classList.contains('pick')) return; e.target.checked ? picked.add(e.target.dataset.id) : picked.delete(e.target.dataset.id); render() }
$('#selclear').onclick = () => { picked.clear(); render() }
$('#mk').onclick = () => { location = '/medley?songs=' + [...picked].map(encodeURIComponent).join(',') }

// ---- add song ----
$('#add').onclick = () => { $('#err').textContent = ''; $('#dlg').showModal(); $('#url').focus() }
$('#close').onclick = () => $('#dlg').close()
async function submit(body, btn) {
  $('#err').textContent = ''
  btn.disabled = true
  const label = btn.textContent
  btn.textContent = 'Working...'
  try {
    const r = await api('/api/songs/import', { method: 'POST', body })
    location = '/song?id=' + encodeURIComponent(r.id)
  } catch (e) {
    $('#err').textContent = e.message
    btn.disabled = false
    btn.textContent = label
  }
}
$('#doImport').onclick = e => submit({ url: $('#url').value }, e.target)
$('#doPaste').onclick = e => submit({ text: $('#text').value, title: $('#ptitle').value, artist: $('#partist').value }, e.target)

// ---- save everything for offline use: the service worker caches what these requests fetch ----
$('#offline-save').dataset.needsNet = ''
$('#offline-save').onclick = async () => {
  const msg = $('#offline-msg'), btn = $('#offline-save')
  btn.disabled = true
  try {
    const list = await api('/api/songs')
    const [sl, ml] = await Promise.all([api('/api/setlists'), api('/api/medleys')])
    const urls = [
      '/', '/song', '/setlists', '/setlist', '/medley', '/style.css', '/api.js', '/chords.js', '/music.js', '/sheet.js', '/scroll.js', '/viewer.js', '/song.js', '/app.js',
      '/setlists.js', '/setlist.js', '/medley.js', '/reorder.js', '/share-ui.js', '/manifest.webmanifest', '/icon.svg',
      '/api/songs', '/api/setlists', '/api/medleys',
      ...list.flatMap(s => [`/api/songs/${s.id}`, `/api/prefs/${encodeURIComponent('song:' + s.id)}`]),
      ...sl.map(x => `/api/setlists/${x.id}/full`), ...ml.map(x => `/api/medleys/${x.id}/full`),
    ]
    let done = 0
    const next = async () => { while (urls.length) { const u = urls.pop(); await fetch(u).catch(() => {}); msg.textContent = `Saving ${++done}...` } }
    await Promise.all([next(), next(), next(), next(), next(), next()])
    msg.textContent = `Saved ${list.length} songs, ${sl.length} setlists and ${ml.length} medleys for offline use.`
  } catch (e) { msg.textContent = e.message }
  btn.disabled = false
  applyNet()
}

load().catch(e => { $('#empty').hidden = false; $('#empty').textContent = e.message })
