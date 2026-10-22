// "Share" dialog for a song, setlist or medley: create, copy and revoke read-only links.
import { api, esc } from './api.js'

export async function openShareDialog(type, id, label) {
  document.getElementById('sharedlg')?.remove()
  const dlg = document.createElement('dialog')
  dlg.id = 'sharedlg'
  document.body.append(dlg)

  async function draw(err = '') {
    const list = await api(`/api/shares?type=${type}&id=${encodeURIComponent(id)}`)
    dlg.innerHTML = `<h2 class="font-brand">Share ${esc(label)}</h2>
      <p class="muted">Anyone with a link can view it, read-only, without signing in.</p>
      <section><div class="row"><select id="sexp"><option value="30d">Expires in 30 days</option><option value="7d">Expires in 7 days</option><option value="never">Never expires</option></select><button id="smake" class="primary">Create link</button></div></section>
      <ul class="shares">${list.map(s => `<li><input readonly value="${esc(location.origin + s.url)}"><button class="btn" data-copy>Copy</button><button class="btn" data-rev="${esc(s.token)}">Revoke</button><span class="muted">${s.expires ? 'Expires ' + new Date(s.expires).toLocaleDateString() : 'No expiry'}</span></li>`).join('') || '<li class="muted">No active links.</li>'}</ul>
      <p class="err">${esc(err)}</p><button id="sclose" class="btn">Close</button>`
  }

  dlg.addEventListener('click', async e => {
    try {
      if (e.target.id === 'sclose') dlg.close()
      else if (e.target.id === 'smake') { await api('/api/shares', { method: 'POST', body: { type, id, expires: dlg.querySelector('#sexp').value } }); await draw() }
      else if (e.target.dataset.rev) { await api('/api/shares/' + e.target.dataset.rev, { method: 'DELETE' }); await draw() }
      else if ('copy' in e.target.dataset) {
        const input = e.target.parentElement.querySelector('input')
        input.select()
        try { await navigator.clipboard.writeText(input.value); e.target.textContent = 'Copied' } catch { document.execCommand('copy') }
      }
    } catch (x) { await draw(x.message) }
  })
  await draw()
  dlg.showModal()
}
