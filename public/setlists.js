import { api, esc, applyNet, $ } from './api.js'

async function load() {
  const lists = await api('/api/setlists')
  $('#list').innerHTML = lists.map(l => `<li><a href="/setlist?id=${encodeURIComponent(l.id)}"><span class="t">${esc(l.name)}</span><span class="a">${l.count} songs</span></a><button data-del="${esc(l.id)}" data-needs-net>Delete</button></li>`).join('')
  $('#empty').hidden = lists.length > 0
  applyNet()
}

$('#make').onclick = async () => {
  $('#err').textContent = ''
  try {
    const l = await api('/api/setlists', { method: 'POST', body: { name: $('#name').value } })
    location = '/setlist?id=' + encodeURIComponent(l.id)
  } catch (e) { $('#err').textContent = e.message }
}
$('#name').onkeydown = e => { if (e.key === 'Enter') $('#make').click() }
$('#list').onclick = async e => {
  const id = e.target.dataset.del
  if (!id || !confirm('Delete this setlist? The songs stay in your library.')) return
  await api('/api/setlists/' + encodeURIComponent(id), { method: 'DELETE' })
  load()
}
load().catch(e => { $('#err').textContent = e.message })
