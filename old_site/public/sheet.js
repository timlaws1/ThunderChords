import { classify } from './chords.js'

const esc = s => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
const CLS = { chord: 'ch', section: 'sec', tab: 'tab', tuning: 'tun', lyric: '', blank: '' }

// One <div> per line, grouped into blocks (runs of non-blank lines) so columns can avoid splitting them.
// marks is an optional array of extra CSS classes per line index (e.g. 'chg', 'add', 'del').
export function renderSheet(text, marks = null) {
  const lines = text.replace(/\s+$/, '').split('\n')
  const types = classify(lines)
  let out = '', open = false
  const close = () => { if (open) { out += '</div>'; open = false } }
  lines.forEach((l, i) => {
    const t = types[i]
    const mark = marks?.[i] ? ` ${marks[i]}` : ''
    if (t === 'blank') { close(); out += `<div class="l${mark}"></div>`; return }
    if (t === 'divider') { close(); out += `<div class="l dv${mark}">${esc(l.trim().replace(/^=+\s*|\s*=+$/g, ''))}</div>`; return }
    if (!open) { out += '<div class="blk">'; open = true }
    const shown = t === 'lyric' ? l.replace(/^(\s*)\\/, '$1') : l
    out += `<div class="l ${CLS[t]}${mark}">${esc(shown)}</div>`
  })
  close()
  return out
}

// Line-level diff marks for side-by-side re-import: chg, add, del.
export function diffMarks(aText, bText) {
  const a = aText.replace(/\s+$/, '').split('\n')
  const b = bText.replace(/\s+$/, '').split('\n')
  const n = a.length, m = b.length
  const dp = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1))
  for (let i = n - 1; i >= 0; i--)
    for (let j = m - 1; j >= 0; j--)
      dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1])
  const aMarks = Array(n).fill(''), bMarks = Array(m).fill('')
  let i = 0, j = 0
  while (i < n || j < m) {
    if (i < n && j < m && a[i] === b[j]) { i++; j++; continue }
    if (i < n && j < m && dp[i + 1][j] === dp[i][j + 1]) {
      aMarks[i++] = 'chg'; bMarks[j++] = 'chg'
      continue
    }
    if (j >= m || (i < n && dp[i + 1][j] >= dp[i][j + 1])) aMarks[i++] = 'del'
    else bMarks[j++] = 'add'
  }
  return { aMarks, bMarks }
}
