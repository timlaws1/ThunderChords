import { api, esc, store, queuePrefs, applyNet, $ } from './api.js'
import { composeMedley } from './music.js'
import { mountViewer } from './viewer.js'

const qs = new URLSearchParams(location.search)
const token = location.pathname.startsWith('/s/') ? location.pathname.split('/')[2] : null
const viewer = mountViewer()
let bundle, song, index = 0

// ---- prefs adapters: saved on the server (and cached locally), or local only on shared pages ----
function serverPrefs(key, { dropTC = null } = {}) {
  const lk = 'prefs:' + key
  let timer, pending = {}
  return {
    async load() {
      let prefs = null, global = null
      try { ({ prefs, global } = await api('/api/prefs/' + encodeURIComponent(key))) ; store.set(lk, JSON.stringify({ prefs, global })) }
      catch { try { ({ prefs, global } = JSON.parse(store.get(lk)) || {}) } catch {} }
      return { prefs: prefs || {}, global: global || {}, force: dropTC }
    },
    save(P) {
      const { t, c, ...rest } = P
      const body = dropTC ? rest : P
      store.set(lk, JSON.stringify({ prefs: body, global: null }))
      pending = { ...pending, ...body }
      clearTimeout(timer)
      timer = setTimeout(async () => {
        const send = pending; pending = {}
        try { await api('/api/prefs/' + encodeURIComponent(key), { method: 'PUT', body: send }) } catch (e) { if (e.offline) queuePrefs(key, send) }
      }, 500)
    },
    async saveGlobalSize(size) { try { await api('/api/prefs/_global', { method: 'PUT', body: { size } }) } catch (e) { if (e.offline) queuePrefs('_global', { size }) } },
  }
}
function localPrefs(key) {
  const lk = 'share:' + key
  return {
    async load() { try { return { prefs: JSON.parse(store.get(lk)) || {}, global: {}, force: null } } catch { return { prefs: {}, global: {}, force: null } } },
    save(P) { store.set(lk, JSON.stringify(P)) },
  }
}
const prefsFor = (key, opts) => (token ? localPrefs(token + ':' + key) : serverPrefs(key, opts))

const songDoc = s => ({ title: s.title, artist: s.artist, key: s.key, capo: s.capo, body: s.body, transposable: true })
const removedDoc = { title: 'Song removed', body: '(this song was deleted)', transposable: false, text: '(this song was deleted)' }

function fail(msg) {
  document.body.innerHTML = `<p style="padding:2rem">${esc(msg)} <a href="/">Back to the library</a></p>`
  throw new Error(msg)
}

// ---- load whatever this page was asked for ----
async function start() {
  try {
    if (token) bundle = await api('/api/shared/' + token)
    else if (qs.get('setlist')) bundle = await api(`/api/setlists/${encodeURIComponent(qs.get('setlist'))}/full`)
    else if (qs.get('medley')) bundle = await api(`/api/medleys/${encodeURIComponent(qs.get('medley'))}/full`)
    else bundle = { type: 'song', song: await api('/api/songs/' + encodeURIComponent(qs.get('id'))) }
  } catch (e) { fail(e.message) }

  document.querySelectorAll('[data-mgmt]').forEach(b => { b.hidden = !!token })
  if (token) $('#home').hidden = true
  $('#print').onclick = () => print()

  if (bundle.type === 'song') {
    song = bundle.song
    await viewer.load(songDoc(song), prefsFor('song:' + song.id))
    if (!token) { manageSong(); api(`/api/songs/${song.id}/opened`, { method: 'POST' }).catch(() => {}) }
  } else if (bundle.type === 'setlist') {
    index = Math.min(Math.max(+qs.get('i') || 0, 0), Math.max(bundle.setlist.items.length - 1, 0))
    if (!token) $('#home').href = '/setlist?id=' + bundle.setlist.id
    $('#home').textContent = token ? '' : 'Setlist'
    await playSetlist()
  } else {
    const m = bundle.medley
    const text = composeMedley(m.items, bundle.songs)
    $('#home').href = token ? '#' : '/medley?id=' + m.id
    $('#home').textContent = token ? '' : 'Edit medley'
    if (token) $('#home').hidden = true
    $('#share').hidden = !!token
    if (!token) $('#share').onclick = () => import('./share-ui.js').then(x => x.openShareDialog('medley', m.id, m.name))
    await viewer.load({ title: m.name, artist: 'Medley', text, transposable: false, meta: `${m.items.length} songs` }, prefsFor('medley:' + m.id))
  }
  applyNet()
}

// ---- setlist play mode: Previous / Next, with the setlist's own transpose and capo ----
async function playSetlist() {
  const items = bundle.setlist.items
  const nav = $('#plnav')
  nav.hidden = !items.length
  if (!items.length) return fail('This setlist is empty.')
  const it = items[index]
  const s = bundle.songs[it.songId]
  $('#plpos').textContent = `${index + 1} of ${items.length}`
  $('#plprev').disabled = index === 0
  $('#plnext').disabled = index === items.length - 1
  if (!token) history.replaceState(null, '', `/song?setlist=${encodeURIComponent(bundle.setlist.id)}&i=${index}`)
  if (s) await viewer.load(songDoc(s), token ? localPrefs(token + ':' + s.id) : serverPrefs('song:' + s.id, { dropTC: { t: it.t, c: it.c } }))
  else await viewer.load(removedDoc, localPrefs('removed'))
  if (!token && s) api(`/api/songs/${s.id}/opened`, { method: 'POST' }).catch(() => {})
}
const step = d => { const n = index + d; if (bundle?.type === 'setlist' && n >= 0 && n < bundle.setlist.items.length) { index = n; playSetlist() } }
$('#plprev').onclick = () => step(-1)
$('#plnext').onclick = () => step(1)
addEventListener('keydown', e => {
  if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || document.querySelector('dialog[open]')) return
  if (e.key === ',') step(-1)
  else if (e.key === '.') step(1)
})

// ---- song management (not shown on shared pages) ----
function manageSong() {
  for (const b of ['#addset', '#share', '#edit']) $(b).hidden = false
  $('#reimport').hidden = !song.source
  const ed = $('#editor')
  const view = on => { for (const sel of ['#sheet', '#tools', '#meta']) $(sel).hidden = !on; ed.hidden = on }

  $('#edit').onclick = () => {
    viewer.stop()
    $('#f-title').value = song.title; $('#f-artist').value = song.artist; $('#f-key').value = song.key
    $('#f-capo').value = song.capo || ''; $('#f-tags').value = song.tags?.join(', ') || ''; $('#f-body').value = song.body
    view(false)
  }
  $('#cancel').onclick = () => view(true)
  ed.onsubmit = async e => {
    e.preventDefault()
    try {
      const saved = await api('/api/songs/' + encodeURIComponent(song.id), { method: 'PUT', body: { title: $('#f-title').value, artist: $('#f-artist').value, key: $('#f-key').value, capo: $('#f-capo').value, tags: $('#f-tags').value, body: $('#f-body').value } })
      song = saved
      view(true)
      await viewer.load(songDoc(song), prefsFor('song:' + song.id))
    } catch (x) { alert(x.message) }
  }
  $('#del').onclick = async () => {
    if (!confirm(`Delete "${song.title}"? This removes the file.`)) return
    await api('/api/songs/' + encodeURIComponent(song.id), { method: 'DELETE' })
    location = '/'
  }

  $('#share').onclick = () => import('./share-ui.js').then(x => x.openShareDialog('song', song.id, song.title))

  // re-import: preview the fresh version next to the current one, then replace on confirm
  const rd = $('#rdlg')
  $('#rclose').onclick = () => rd.close()
  $('#reimport').onclick = async () => {
    $('#rmsg').textContent = 'Fetching the source page...'
    for (const x of ['#rcmp', '#rok', '#rnote']) $(x).hidden = true
    rd.showModal()
    try {
      const r = await api(`/api/songs/${encodeURIComponent(song.id)}/reimport`, { method: 'POST' })
      $('#rold').textContent = r.current; $('#rnew').textContent = r.body
      $('#rmsg').textContent = r.body.trim() === r.current.trim() ? 'The source matches what you have. Nothing to change.' : 'Compare the two versions.'
      for (const x of ['#rcmp', '#rnote']) $(x).hidden = false
      $('#rok').hidden = r.body.trim() === r.current.trim()
      $('#rok').onclick = async () => {
        song = await api(`/api/songs/${encodeURIComponent(song.id)}/replace`, { method: 'POST', body: { body: r.body, key: r.key, capo: r.capo } })
        rd.close()
        await viewer.load(songDoc(song), prefsFor('song:' + song.id))
      }
    } catch (e) { $('#rmsg').textContent = e.message }
  }

  // add to setlist
  const ld = $('#ldlg')
  $('#lclose').onclick = () => ld.close()
  $('#addset').onclick = async () => {
    $('#lerr').textContent = ''
    const lists = await api('/api/setlists')
    $('#lsel').innerHTML = lists.map(l => `<option value="${esc(l.id)}">${esc(l.name)} (${l.count})</option>`).join('') || '<option value="">No setlists yet</option>'
    ld.showModal()
  }
  $('#lok').onclick = async () => {
    try {
      const P = viewer.state()
      const item = { songId: song.id, t: P.t, c: P.c }
      const name = $('#lnew').value.trim()
      if (name) await api('/api/setlists', { method: 'POST', body: { name, items: [item] } })
      else if ($('#lsel').value) {
        const l = await api('/api/setlists/' + encodeURIComponent($('#lsel').value))
        await api('/api/setlists/' + encodeURIComponent(l.id), { method: 'PUT', body: { items: [...l.items, item] } })
      } else return ($('#lerr').textContent = 'Pick a setlist or type a name.')
      $('#lnew').value = ''
      ld.close()
    } catch (e) { $('#lerr').textContent = e.message }
  }
}

start()
