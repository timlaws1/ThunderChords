import { api, esc, applyNet, $ } from './api.js'
import { transposeChord, usesFlats } from './chords.js'
import { sectionsOf, hasMarkers, guessKey, shiftFor, orderByKey, composeMedley, medleySource } from './music.js'
import { renderSheet } from './sheet.js'
import { enableReorder, moveItem } from './reorder.js'

const qs = new URLSearchParams(location.search)
let id = qs.get('id')
let items = [], songs = {}, editIndex = -1
const NOTES = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B']
$('#keys').innerHTML = NOTES.flatMap(n => [n, n + 'm']).map(k => `<option value="${k}">`).join('')

const status = s => { $('#status').textContent = s }
const keyOf = it => { const s = songs[it.songId]; return s ? guessKey(s.body, s.key) : null }
const hasCustom = it => typeof it.body === 'string' && !!it.body.trim()

function row(it, i) {
  const s = songs[it.songId]
  if (!s) return `<li data-i="${i}"><span class="grip">&equiv;</span><div class="meta"><b>Song removed</b></div><button class="up">Up</button><button class="down">Down</button><button data-a="rm">Remove</button></li>`
  const g = keyOf(it)
  const key = g ? `${transposeChord(g.key, it.t, usesFlats(s.body))}${it.guess || g.guessed ? ' (guessed)' : ''}` : 'no key'
  let sections
  if (hasCustom(it)) {
    sections = '<span class="muted">Custom text for this medley</span>'
  } else if (hasMarkers(s.body)) {
    const secs = sectionsOf(s.body)
    sections = `<details><summary>${it.sections?.length ? `${it.sections.length} of ${secs.length} sections` : 'Whole song'}</summary><div class="secs">${secs.map(x => `<label><input type="checkbox" data-sec="${esc(x.id)}"${it.sections?.includes(x.id) ? ' checked' : ''}> ${esc(x.id)}</label>`).join('')}</div></details>`
  } else {
    sections = '<span class="muted">No section markers, whole song</span>'
  }
  return `<li data-i="${i}"><span class="grip" title="Drag to reorder">&equiv;</span>
    <div class="meta"><b>${esc(s.title)}</b><span class="muted">${esc(s.artist)}, key ${esc(key)}</span>${sections}</div>
    <div class="grp"><span>T</span><button data-a="t-">-</button><b>${it.t > 0 ? '+' : ''}${it.t}</b><button data-a="t+">+</button></div>
    <div class="grp"><span>C</span><button data-a="c-">-</button><b>${it.c}</b><button data-a="c+">+</button></div>
    <button data-a="edit">Edit text</button>
    <button class="up" aria-label="Move up">Up</button><button class="down" aria-label="Move down">Down</button><button data-a="rm">Remove</button></li>`
}

function render() {
  $('#items').innerHTML = items.map(row).join('')
  $('#empty').hidden = items.length > 0
  $('#preview').innerHTML = items.length ? `<div class="sheet">${renderSheet(composeMedley(items, songs))}</div>` : ''
  $('#play').hidden = $('#share').hidden = !id
  if (id) $('#play').href = `/song?medley=${encodeURIComponent(id)}`
  if (!id && items.length) status('Not saved yet — name it and Save before Play.')
}

addEventListener('beforeunload', e => {
  if (!id && items.length) { e.preventDefault(); e.returnValue = '' }
})

enableReorder($('#items'), (from, to) => { items = moveItem(items, from, to); render() })
$('#items').addEventListener('click', e => {
  const a = e.target.dataset.a
  if (!a) return
  const i = +e.target.closest('li').dataset.i, it = items[i]
  if (a === 'edit') return openEdit(i)
  if (a === 'rm') items.splice(i, 1)
  else if (a[0] === 't') it.t = Math.min(11, Math.max(-11, it.t + (a[1] === '+' ? 1 : -1)))
  else it.c = Math.min(11, Math.max(0, it.c + (a[1] === '+' ? 1 : -1)))
  render()
})
$('#items').addEventListener('change', e => {
  if (!e.target.dataset.sec) return
  const li = e.target.closest('li'), it = items[+li.dataset.i]
  if (hasCustom(it)) return
  const ticked = [...li.querySelectorAll('[data-sec]:checked')].map(x => x.dataset.sec)
  it.sections = ticked.length ? ticked : null
  render()
})

function openEdit(i) {
  const it = items[i], s = songs[it.songId]
  if (!s) return
  editIndex = i
  $('#edtitle').textContent = `Edit text — ${s.title}`
  $('#ebody').value = medleySource(it, s)
  $('#eerr').textContent = ''
  $('#edlg').showModal()
  $('#ebody').focus()
}
$('#eclose').onclick = () => $('#edlg').close()
$('#edlg').addEventListener('click', e => { if (e.target === $('#edlg')) $('#edlg').close() })
$('#eok').onclick = () => {
  const text = $('#ebody').value.replace(/\r\n/g, '\n').replace(/\s+$/, '')
  if (!text.trim()) return ($('#eerr').textContent = 'Text cannot be empty.')
  const it = items[editIndex]
  it.body = text + '\n'
  it.sections = null
  $('#edlg').close()
  status('Custom text set for this medley. Save when ready.')
  render()
}
$('#ereset').onclick = () => {
  const it = items[editIndex]
  delete it.body
  $('#edlg').close()
  status('Using the original song text again.')
  render()
}

$('#match').onclick = () => {
  const target = $('#tkey').value.trim()
  if (!target) return status('Type a target key first.')
  let guessed = 0
  for (const it of items) {
    const g = keyOf(it)
    if (!g) continue
    it.t = shiftFor(g.key, target)
    it.c = 0
    it.guess = g.guessed
    if (g.guessed) guessed++
  }
  status(guessed ? `Matched. ${guessed} keys were guessed from the first chord, check them.` : 'Matched.')
  render()
}
$('#order').onclick = () => {
  const keys = items.map(it => { const g = keyOf(it); return g ? transposeChord(g.key, it.t) : null })
  const order = orderByKey(keys)
  items = order.map(i => items[i])
  status('Reordered so neighbouring songs are close in key.')
  render()
}

let st
$('#sq').oninput = () => {
  clearTimeout(st)
  st = setTimeout(async () => {
    const q = $('#sq').value.trim()
    if (!q) { $('#results').innerHTML = ''; return }
    const found = (await api('/api/songs?q=' + encodeURIComponent(q))).slice(0, 20)
    $('#results').innerHTML = found.map(s => `<li><span class="meta"><b>${esc(s.title)}</b> <span class="muted">${esc(s.artist)}</span></span><button data-add="${esc(s.id)}" data-needs-net>Add</button></li>`).join('')
    applyNet()
  }, 200)
}
$('#results').onclick = async e => {
  const sid = e.target.dataset.add
  if (!sid) return
  if (items.some(i => i.songId === sid)) return status('Already in list')
  songs[sid] ||= await api('/api/songs/' + encodeURIComponent(sid))
  items.push({ songId: sid, t: 0, c: 0, sections: null })
  render()
  status(id ? `Added ${songs[sid].title}.` : 'Not saved yet — name it and Save before Play.')
  $('#items').scrollIntoView({ block: 'nearest' })
}

$('#save').onclick = async () => {
  const name = $('#name').value.trim()
  if (!name) return status('Give the medley a name.')
  if (!items.length) return status('Add at least one song.')
  try {
    const body = { name, targetKey: $('#tkey').value.trim(), items }
    const m = id ? await api('/api/medleys/' + encodeURIComponent(id), { method: 'PUT', body }) : await api('/api/medleys', { method: 'POST', body })
    id = m.id
    history.replaceState(null, '', '/medley?id=' + encodeURIComponent(id))
    status('Saved.')
    render()
  } catch (e) { status(e.message) }
}
$('#share').onclick = () => import('./share-ui.js').then(x => x.openShareDialog('medley', id, $('#name').value))

try {
  if (id) {
    const b = await api(`/api/medleys/${encodeURIComponent(id)}/full`)
    items = b.medley.items; songs = b.songs
    $('#name').value = b.medley.name; $('#tkey').value = b.medley.targetKey || ''
  } else {
    for (const sid of (qs.get('songs') || '').split(',').filter(Boolean)) {
      try { songs[sid] = await api('/api/songs/' + encodeURIComponent(sid)); items.push({ songId: sid, t: 0, c: 0, sections: null }) } catch {}
    }
  }
  render()
} catch (e) { document.body.innerHTML = `<p style="padding:2rem">${esc(e.message)} <a href="/">Back to the library</a></p>` }
applyNet()
