import { classify } from './chords.js'

const esc = s => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
const CLS = { chord: 'ch', section: 'sec', tab: 'tab', tuning: 'tun', lyric: '', blank: '' }

// One <div> per line, grouped into blocks (runs of non-blank lines) so columns can avoid splitting them.
export function renderSheet(text) {
  const lines = text.replace(/\s+$/, '').split('\n')
  const types = classify(lines)
  let out = '', open = false
  const close = () => { if (open) { out += '</div>'; open = false } }
  lines.forEach((l, i) => {
    const t = types[i]
    if (t === 'blank') { close(); out += '<div class="l"></div>'; return }
    if (t === 'divider') { close(); out += `<div class="l dv">${esc(l.trim().replace(/^=+\s*|\s*=+$/g, ''))}</div>`; return }
    if (!open) { out += '<div class="blk">'; open = true }
    const shown = t === 'lyric' ? l.replace(/^(\s*)\\/, '$1') : l
    out += `<div class="l ${CLS[t]}">${esc(shown)}</div>`
  })
  close()
  return out
}
