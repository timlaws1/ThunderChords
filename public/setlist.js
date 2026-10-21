import { api, esc, applyNet, $ } from './api.js'
import { enableReorder, moveItem } from './reorder.js'

const id = new URLSearchParams(location.search).get('id')
let setlist, items = [], songs = {}, timer
const idxKey = 'setlist:' + id + ':i'

const status = s => { $('#status').textContent = s }
function saveSoon() {
  status('Saving...')
  clearTimeout(timer)
  timer = setTimeout(async () => {
    try { await api('/api/setlists/' + encodeURIComponent(id), { method: 'PUT', body: { name: $('#name').value, items } }); status('Saved.') }
    catch (e) { status(e.message) }
  }, 400)
}

function resumeIndex() {
  let i = 0
  try { i = +sessionStorage.getItem(idxKey) || 0 } catch {}
  if (i < 0 || i >= items.length) i = 0
  return i
}

function syncPlay() {
  const play = $('#play')
  if (items.length) {
    play.href = `/song?setlist=${encodeURIComponent(id)}&i=${resumeIndex()}`
    play.removeAttribute('aria-disabled')
  } else {
    play.href = '#'
    play.setAttribute('aria-disabled', 'true')
  }
}

const row = (it, i) => {
  const s = songs[it.songId]
  const title = s?.title || 'Song removed'
  const link = s
    ? `<a href="/song?id=${encodeURIComponent(it.songId)}"><b>${esc(title)}</b></a>`
    : `<b>${esc(title)}</b>`
  return `<li data-i="${i}"><span class="grip" title="Drag to reorder" aria-label="Drag to reorder">&equiv;</span>
    <div class="meta">${link}<span class="muted">${esc(s?.artist || '')}</span></div>
    <div class="grp"><span>T</span><button data-a="t-">-</button><b>${it.t > 0 ? '+' : ''}${it.t}</b><button data-a="t+">+</button></div>
    <div class="grp"><span>C</span><button data-a="c-">-</button><b>${it.c}</b><button data-a="c+">+</button></div>
    <button class="up" aria-label="Move up">Up</button><button class="down" aria-label="Move down">Down</button><button data-a="rm">Remove</button></li>`
}
function render() {
  $('#items').innerHTML = items.map(row).join('')
  $('#empty').hidden = items.length > 0
  syncPlay()
}

enableReorder($('#items'), (from, to) => { items = moveItem(items, from, to); render(); saveSoon() })
$('#items').addEventListener('click', e => {
  const a = e.target.dataset.a
  if (!a) return
  const i = +e.target.closest('li').dataset.i, it = items[i]
  if (a === 'rm') items.splice(i, 1)
  else if (a[0] === 't') it.t = Math.min(11, Math.max(-11, it.t + (a[1] === '+' ? 1 : -1)))
  else it.c = Math.min(11, Math.max(0, it.c + (a[1] === '+' ? 1 : -1)))
  render(); saveSoon()
})
$('#name').oninput = saveSoon
$('#share').onclick = () => import('./share-ui.js').then(x => x.openShareDialog('setlist', id, $('#name').value))

let st
$('#sq').oninput = () => {
  clearTimeout(st)
  st = setTimeout(async () => {
    const q = $('#sq').value.trim()
    if (!q) { $('#results').innerHTML = ''; return }
    const found = (await api('/api/songs?q=' + encodeURIComponent(q))).slice(0, 20)
    for (const s of found) songs[s.id] ||= s
    $('#results').innerHTML = found.map(s => `<li><span class="meta"><b>${esc(s.title)}</b> <span class="muted">${esc(s.artist)}</span></span><button data-add="${esc(s.id)}" data-needs-net>Add</button></li>`).join('')
    applyNet()
  }, 200)
}
$('#results').onclick = e => {
  const sid = e.target.dataset.add
  if (!sid) return
  if (items.some(i => i.songId === sid)) return status('Already in list')
  items.push({ songId: sid, t: 0, c: 0 })
  status(`Added ${songs[sid]?.title || 'song'}.`)
  render()
  saveSoon()
  $('#items').scrollIntoView({ block: 'nearest' })
}

try {
  const b = await api(`/api/setlists/${encodeURIComponent(id)}/full`)
  setlist = b.setlist; items = setlist.items; songs = b.songs
  $('#name').value = setlist.name
  document.title = setlist.name
  status('Saved.')
  render()
} catch (e) { document.body.innerHTML = `<p style="padding:2rem">${esc(e.message)} <a href="/setlists">Back to setlists</a></p>` }
applyNet()
