import { api, esc, store, applyNet, $ } from './api.js'
import { renderSheet } from './sheet.js'

const UNTAGGED = '__untagged__'
let allSongs = [], songs = [], medleys = [], tag = null, view = 'all'
const picked = new Set()
const importTags = new Set()
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

function tagCounts() {
  const counts = new Map()
  let untagged = 0
  for (const s of allSongs) {
    const tags = s.tags || []
    if (!tags.length) { untagged++; continue }
    for (const t of tags) counts.set(t, (counts.get(t) || 0) + 1)
  }
  return { counts, untagged }
}

function matchesTag(s) {
  if (tag == null) return true
  const tags = s.tags || []
  if (tag === UNTAGGED) return tags.length === 0
  return tags.includes(tag)
}

function activeSort() {
  if (view === 'added') return 'added'
  if (view === 'opened') return 'opened'
  return $('#sort').value
}

function renderCats() {
  const { counts, untagged } = tagCounts()
  const names = [...counts.keys()].sort((a, b) => a.localeCompare(b))
  if (tag && tag !== UNTAGGED && !counts.has(tag)) tag = null
  if (tag === UNTAGGED && !untagged) tag = null

  const rows = [
    `<button type="button" class="cat${tag == null ? ' on' : ''}" data-t="">All <span class="n">${allSongs.length}</span></button>`,
    ...names.map(t => `<button type="button" class="cat${t === tag ? ' on' : ''}" data-t="${esc(t)}">${esc(t)} <span class="n">${counts.get(t)}</span></button>`),
  ]
  if (untagged) {
    rows.push(`<button type="button" class="cat${tag === UNTAGGED ? ' on' : ''}" data-t="${UNTAGGED}">Untagged <span class="n">${untagged}</span></button>`)
  }
  $('#tags').innerHTML = rows.join('')
}

function renderStats() {
  const artists = new Set(allSongs.map(s => s.artist).filter(Boolean)).size
  const cats = tagCounts().counts.size
  $('#stats').innerHTML = [
    ['Total songs', allSongs.length],
    ['Artists', artists],
    ['Categories', cats],
    ['Medleys', medleys.length],
  ].map(([label, n]) => `<div class="stat"><b>${n}</b><span>${label}</span></div>`).join('')
}

function renderRecent() {
  const recent = [...allSongs]
    .filter(s => s.lastOpened)
    .sort(sorters.opened)
    .slice(0, 6)
  $('#recent').innerHTML = recent.map(s =>
    `<li><a href="/song?id=${encodeURIComponent(s.id)}"><span class="t">${esc(s.title)}</span><span class="a">${esc(s.artist)}</span></a></li>`
  ).join('')
  $('#recent-empty').hidden = recent.length > 0
}

function songMeta(s) {
  const bits = []
  if (s.capo) bits.push(`<span class="capo">Capo ${esc(s.capo)}</span>`)
  for (const t of (s.tags || [])) bits.push(`<span class="tag">${esc(t)}</span>`)
  return bits.length ? `<span class="meta-row">${bits.join('')}</span>` : ''
}

function render() {
  const kind = $('#kind').value
  const sort = activeSort()
  renderCats()
  renderStats()
  renderRecent()

  for (const b of $('#viewtabs').querySelectorAll('[data-view]')) {
    const on = b.dataset.view === view
    b.classList.toggle('on', on)
    b.setAttribute('aria-selected', on ? 'true' : 'false')
  }
  $('#sort').disabled = view !== 'all'

  const q = $('#q').value.toLowerCase().trim()
  const med = medleys.filter(m => !q || m.name.toLowerCase().includes(q)).map(m => ({
    id: m.id, title: m.name, artist: `Medley, ${m.count} songs`, key: '', capo: 0, tags: [],
    added: m.created || '', lastOpened: '', medley: true,
  }))
  let rows = [
    ...(kind === 'medley' ? [] : songs.filter(matchesTag)),
    ...(kind === 'song' || tag != null ? [] : med),
  ].sort(sorters[sort])

  $('#list').innerHTML = rows.map(s => {
    const date = sort === 'opened' && s.lastOpened ? `<span class="a">Opened ${new Date(s.lastOpened).toLocaleDateString()}</span>` : ''
    const href = s.medley ? `/song?medley=${encodeURIComponent(s.id)}` : `/song?id=${encodeURIComponent(s.id)}`
    const box = s.medley ? '<span></span>' : `<input type="checkbox" class="pick" data-id="${esc(s.id)}" aria-label="Select ${esc(s.title)}"${picked.has(s.id) ? ' checked' : ''}>`
    const acts = s.medley
      ? `<span class="rowacts"><a class="btn" href="/medley?id=${encodeURIComponent(s.id)}" data-needs-net>Edit</a><button type="button" data-del-medley="${esc(s.id)}" data-needs-net>Delete</button></span>`
      : ''
    const key = s.key ? `<span class="k">${esc(s.key)}</span>` : s.medley ? '<span class="k">Medley</span>' : ''
    return `<li class="group">${box}<a href="${href}"><span class="t">${esc(s.title)}</span><span class="a">${esc(s.artist)}</span>${date}${key}${songMeta(s)}</a>${acts}</li>`
  }).join('')
  $('#empty').hidden = rows.length > 0
  $('#selbar').hidden = picked.size === 0
  $('#selcount').textContent = `${picked.size} selected`
  $('#mk').disabled = picked.size < 2
  applyNet()
}

async function ensureAllSongs() {
  if (!allSongs.length) allSongs = await api('/api/songs')
}

async function load() {
  const q = $('#q').value.trim()
  const [s, m] = await Promise.all([
    api('/api/songs' + (q ? '?q=' + encodeURIComponent(q) : '')),
    api('/api/medleys').catch(() => []),
  ])
  songs = s
  medleys = m
  if (!q) allSongs = s
  else await ensureAllSongs()
  render()
}

let t
$('#q').oninput = () => { clearTimeout(t); t = setTimeout(load, 200) }
$('#sort').onchange = () => { store.set('sort', $('#sort').value); render() }
$('#kind').onchange = () => { store.set('kind', $('#kind').value); render() }
$('#viewtabs').onclick = e => {
  const b = e.target.closest('[data-view]')
  if (!b) return
  view = b.dataset.view
  render()
}
$('#tags').onclick = e => {
  const b = e.target.closest('[data-t]')
  if (!b) return
  const next = b.dataset.t
  tag = next === '' ? null : (tag === next ? null : next)
  render()
}
$('#list').onchange = e => { if (!e.target.classList.contains('pick')) return; e.target.checked ? picked.add(e.target.dataset.id) : picked.delete(e.target.dataset.id); render() }
$('#list').onclick = async e => {
  const btn = e.target.closest('[data-del-medley]')
  if (!btn) return
  const mid = btn.dataset.delMedley
  const m = medleys.find(x => x.id === mid)
  if (!confirm(`Delete medley "${m?.name || mid}"?`)) return
  try {
    await api('/api/medleys/' + encodeURIComponent(mid), { method: 'DELETE' })
    medleys = medleys.filter(x => x.id !== mid)
    render()
  } catch (err) { alert(err.message) }
}
$('#selclear').onclick = () => { picked.clear(); render() }
$('#mk').onclick = () => { location = '/medley?songs=' + [...picked].map(encodeURIComponent).join(',') }

// ---- add song ----
let mode = 'link', preview = null, busy = false
const dlg = $('#dlg')

function setMode(m) {
  mode = m
  for (const b of dlg.querySelectorAll('.mode')) {
    const on = b.dataset.mode === m
    b.classList.toggle('on', on)
    b.setAttribute('aria-selected', on ? 'true' : 'false')
  }
  $('#mode-link').hidden = m !== 'link'
  $('#mode-paste').hidden = m !== 'paste'
}

function showStep(step) {
  $('#step-input').hidden = step !== 'input'
  $('#step-preview').hidden = step !== 'preview'
  $('#err').textContent = ''
}

function resetDialog() {
  preview = null
  importTags.clear()
  $('#ftags').value = ''
  showStep('input')
  setMode('link')
  $('#dupnote').hidden = true
  $('#doOpen').hidden = true
  $('#doSave').hidden = false
  $('#doSave').disabled = false
  $('#fkeyhint').hidden = true
  $('#sheetprev').innerHTML = ''
  $('#ftags-chips').innerHTML = ''
  $('#err').textContent = ''
}

function syncDupActions() {
  const dup = !!preview?.duplicateId
  const source = $('#fsource').value.trim()
  const same = dup && source === (preview.source || '')
  $('#dupnote').hidden = !dup
  $('#dupnote').textContent = dup
    ? (same ? 'This link is already in your library.' : 'Source changed — saving will create a new song.')
    : ''
  $('#doOpen').hidden = !dup
  if (dup) $('#doOpen').href = '/song?id=' + encodeURIComponent(preview.duplicateId)
  $('#doSave').hidden = same
  $('#doSave').disabled = same
  $('#doSave').classList.toggle('primary', !dup || !same)
  $('#doOpen').classList.toggle('primary', same)
}

function knownTags() {
  return [...new Set(allSongs.flatMap(s => s.tags || []))].sort((a, b) => a.localeCompare(b))
}

function renderImportTags() {
  const known = knownTags()
  const extra = [...importTags].filter(t => !known.includes(t)).sort((a, b) => a.localeCompare(b))
  $('#ftags-chips').innerHTML = [...known, ...extra].map(t =>
    `<button type="button" class="chip${importTags.has(t) ? ' on' : ''}" data-tag="${esc(t)}">${esc(t)}</button>`
  ).join('')
}

function parseTagInput(raw) {
  return String(raw || '').split(',').map(t => t.trim()).filter(Boolean)
}

function collectImportTags() {
  const tags = new Set(importTags)
  for (const t of parseTagInput($('#ftags').value)) tags.add(t)
  return [...tags]
}

function showPreview(d) {
  preview = d
  importTags.clear()
  $('#ftitle').value = d.title || ''
  $('#fartist').value = d.artist || ''
  $('#fkey').value = d.key || ''
  $('#fcapo').value = d.capo || ''
  $('#fkeyhint').hidden = !d.keyGuessed
  $('#fsource').value = d.source || ''
  $('#fsourcewrap').hidden = !d.source && !d.duplicateId
  $('#ftags').value = ''
  $('#sheetprev').innerHTML = `<div class="sheet">${renderSheet(d.body)}</div>`
  renderImportTags()
  syncDupActions()
  showStep('preview')
  ;(d.duplicateId ? $('#doOpen') : $('#ftitle')).focus()
}
$('#fsource').oninput = syncDupActions
$('#ftags-chips').onclick = e => {
  const b = e.target.closest('[data-tag]')
  if (!b) return
  const t = b.dataset.tag
  if (importTags.has(t)) importTags.delete(t)
  else importTags.add(t)
  renderImportTags()
}

async function runBusy(btn, label, fn) {
  if (busy) return
  busy = true
  $('#err').textContent = ''
  const buttons = [$('#doPreview'), $('#doSave'), $('#doBack')]
  for (const b of buttons) if (b) b.disabled = true
  const prev = btn.textContent
  btn.textContent = label
  try { await fn() }
  catch (e) { $('#err').textContent = e.message }
  finally {
    btn.textContent = prev
    for (const b of buttons) if (b) b.disabled = false
    if (preview) syncDupActions()
    busy = false
  }
}

function openAdd() { resetDialog(); dlg.showModal(); $('#url').focus() }
$('#add').onclick = openAdd
$('#add2').onclick = openAdd
$('#close').onclick = () => dlg.close()
dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close() })
dlg.querySelector('.modes').onclick = e => {
  const b = e.target.closest('[data-mode]')
  if (b) setMode(b.dataset.mode)
}
$('#doBack').onclick = () => { showStep('input'); (mode === 'link' ? $('#url') : $('#text')).focus() }

$('#addform').onsubmit = async e => {
  e.preventDefault()
  if ($('#step-preview').hidden) {
    await runBusy($('#doPreview'), 'Working...', async () => {
      await ensureAllSongs()
      const body = mode === 'link'
        ? { url: $('#url').value.trim() }
        : { text: $('#text').value, title: $('#ptitle').value, artist: $('#partist').value }
      if (mode === 'link' && !body.url) throw new Error('Paste a link first.')
      if (mode === 'paste' && !body.text.trim()) throw new Error('Paste some chords first.')
      showPreview(await api('/api/songs/preview', { method: 'POST', body }))
    })
  } else {
    await runBusy($('#doSave'), 'Saving...', async () => {
      const title = $('#ftitle').value.trim()
      if (!title) {
        $('#ftitle').focus()
        throw new Error('Add a title and try again.')
      }
      const source = $('#fsource').value.trim()
      if (preview?.duplicateId && source === (preview.source || '')) {
        throw new Error('This link is already saved. Open the existing song, or change the source.')
      }
      const r = await api('/api/songs/import', {
        method: 'POST',
        body: {
          title, artist: $('#fartist').value,
          key: $('#fkey').value, capo: $('#fcapo').value,
          tags: collectImportTags().join(', '),
          body: preview.body, source, text: mode === 'paste' ? $('#text').value : undefined,
        },
      })
      location = '/song?id=' + encodeURIComponent(r.id)
    })
  }
}

// ---- save everything for offline use: the service worker caches what these requests fetch ----
$('#offline-save').dataset.needsNet = ''
$('#offline-save').onclick = async () => {
  const msg = $('#offline-msg'), btn = $('#offline-save')
  btn.disabled = true
  try {
    const list = await api('/api/songs')
    const [sl, ml] = await Promise.all([api('/api/setlists'), api('/api/medleys')])
    const assets = [...document.querySelectorAll('link[rel="stylesheet"][href], script[src]')]
      .map(el => el.href || el.src)
      .filter(Boolean)
    const urls = [
      '/', '/song', '/setlists', '/setlist', '/medley', '/theme.js', '/manifest.webmanifest', '/icon.svg',
      '/api/songs', '/api/setlists', '/api/medleys',
      ...assets,
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
