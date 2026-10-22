import { api, esc, store, applyNet, $ } from './api.js'
import { renderSheet } from './sheet.js'

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
    const acts = s.medley
      ? `<span class="rowacts"><a class="btn" href="/medley?id=${encodeURIComponent(s.id)}" data-needs-net>Edit</a><button type="button" data-del-medley="${esc(s.id)}" data-needs-net>Delete</button></span>`
      : ''
    return `<li>${box}<a href="${href}"><span class="t">${esc(s.title)}</span><span class="a">${esc(s.artist)}</span>${date}${s.key ? `<span class="k">${esc(s.key)}</span>` : s.medley ? '<span class="k">Medley</span>' : ''}</a>${acts}</li>`
  }).join('')
  $('#empty').hidden = rows.length > 0
  $('#selbar').hidden = picked.size === 0
  $('#selcount').textContent = `${picked.size} selected`
  $('#mk').disabled = picked.size < 2
  applyNet()
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
  showStep('input')
  setMode('link')
  $('#dupnote').hidden = true
  $('#doOpen').hidden = true
  $('#doSave').hidden = false
  $('#doSave').disabled = false
  $('#fkeyhint').hidden = true
  $('#sheetprev').innerHTML = ''
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

function showPreview(d) {
  preview = d
  $('#ftitle').value = d.title || ''
  $('#fartist').value = d.artist || ''
  $('#fkey').value = d.key || ''
  $('#fcapo').value = d.capo || ''
  $('#fkeyhint').hidden = !d.keyGuessed
  $('#fsource').value = d.source || ''
  $('#fsourcewrap').hidden = !d.source && !d.duplicateId
  $('#sheetprev').innerHTML = `<div class="sheet">${renderSheet(d.body)}</div>`
  syncDupActions()
  showStep('preview')
  ;(d.duplicateId ? $('#doOpen') : $('#ftitle')).focus()
}
$('#fsource').oninput = syncDupActions

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

$('#add').onclick = () => { resetDialog(); dlg.showModal(); $('#url').focus() }
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
    const urls = [
      '/', '/song', '/setlists', '/setlist', '/medley', '/style.css', '/theme.js', '/api.js', '/chords.js', '/music.js', '/sheet.js', '/scroll.js', '/viewer.js', '/song.js', '/app.js',
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
