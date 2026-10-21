// The sheet viewer: render, transpose, capo, text size, autoscroll, two columns, capo finder.
// It is used for a song, a setlist song, a medley and shared links. The page supplies the doc and a prefs adapter:
//   doc   = { title, body, key, capo, transposable } or { text, meta, transposable: false } for a medley
//   prefs = { load() -> { prefs, global, force }, save(P), saveGlobalSize(n) }
import { transposeText, transposeChord, usesFlats } from './chords.js'
import { suggestCapo } from './music.js'
import { renderSheet } from './sheet.js'
import { createScroller } from './scroll.js'
import { esc, store } from './api.js'

const TOOLS = `
<div class="grp" data-tr><span>Transpose</span><button id="tdn" aria-label="Down a semitone">-</button><b id="tval" title="Tap to reset"></b><button id="tup" aria-label="Up a semitone">+</button></div>
<div class="grp" data-tr><span>Capo</span><button id="cdn" aria-label="Capo down">-</button><b id="cval" title="Tap to reset"></b><button id="cup" aria-label="Capo up">+</button></div>
<div class="grp" data-tr><button id="flat" title="Switch between sharps and flats"></button><button id="finder">Easier chords</button></div>
<div class="grp"><span>Text</span><button id="sdn" aria-label="Smaller text">A-</button><button id="sup" aria-label="Larger text">A+</button><button id="cols" hidden></button></div>
<div class="grp" data-scroll><button id="play" class="primary"></button><button id="sl" aria-label="Slower">-</button><b id="sval"></b><button id="sf" aria-label="Faster">+</button></div>
<div class="grp" id="pager" hidden><button id="pprev" aria-label="Previous page">Back</button><b id="pnum"></b><button id="pnext" aria-label="Next page">Next</button></div>`

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

export function mountViewer() {
  const $ = s => document.querySelector(s)
  $('#tools').innerHTML = TOOLS
  const sheet = $('#sheet')
  let doc, prefs, P, page = 0, pages = 1
  let colsPref = store.get('chords:cols') === '1'
  const colsOn = () => colsPref && innerWidth >= 700
  const scroller = createScroller({ getSpeed: () => (P?.speed || 5) * 6, onState: () => controls() })
  const gap = () => parseFloat(getComputedStyle(sheet).columnGap) || 32

  const persist = () => prefs.save({ t: P.t, c: P.c, speed: P.speed, size: P.size, flats: P.flats })

  function controls() {
    if (!P) return
    document.querySelectorAll('[data-tr]').forEach(el => { el.hidden = !doc.transposable })
    $('#tval').textContent = (P.t > 0 ? '+' : '') + P.t
    $('#cval').textContent = P.c
    $('#flat').textContent = P.flats ? 'Flats' : 'Sharps'
    $('#sval').textContent = P.speed
    $('#play').textContent = scroller.playing ? 'Pause' : 'Scroll'
    const c = colsOn()
    $('#cols').hidden = innerWidth < 700
    $('#cols').textContent = c ? '1 column' : '2 columns'
    $('[data-scroll]').hidden = c
    $('#pager').hidden = !c
    document.body.classList.toggle('cols', c)
  }

  function render() {
    document.title = doc.title
    $('#title').textContent = doc.title
    $('#artist').textContent = doc.artist || ''
    sheet.innerHTML = renderSheet(doc.transposable ? transposeText(doc.body, P.t - P.c, P.flats) : doc.text)
    document.documentElement.style.setProperty('--fs', P.size + 'px')
    if (doc.transposable) {
      const key = doc.key && transposeChord(doc.key, P.t, P.flats)
      const capo = (doc.capo || 0) + P.c
      $('#meta').textContent = [key && `Key ${key}`, capo && `capo ${capo}`].filter(Boolean).join(', ')
    } else $('#meta').textContent = doc.meta || ''
    controls()
    requestAnimationFrame(() => { fit(); goPage(page) })
  }

  // Two-column mode: fixed height, sideways pages of two columns each.
  function fit() {
    if (!colsOn()) { sheet.style.height = ''; sheet.scrollLeft = 0; pages = 1; return }
    sheet.style.height = Math.max(240, innerHeight - (sheet.getBoundingClientRect().top + scrollY) - 16) + 'px'
    pages = Math.max(1, Math.ceil((sheet.scrollWidth + gap()) / (sheet.clientWidth + gap()) - 0.01))
  }
  function goPage(p) {
    if (!colsOn()) return
    page = clamp(p, 0, pages - 1)
    sheet.scrollLeft = page * (sheet.clientWidth + gap())
    $('#pnum').textContent = `${page + 1} / ${pages}`
  }

  const bump = (k, d, lo, hi) => {
    P[k] = clamp(P[k] + d, lo, hi)
    persist()
    if (k === 'size') prefs.saveGlobalSize?.(P.size)
    render()
  }
  const on = (id, fn) => { $(id).onclick = fn }
  on('#tdn', () => bump('t', -1, -11, 11)); on('#tup', () => bump('t', 1, -11, 11))
  on('#cdn', () => bump('c', -1, 0, 11)); on('#cup', () => bump('c', 1, 0, 11))
  on('#tval', () => { P.t = 0; persist(); render() }); on('#cval', () => { P.c = 0; persist(); render() })
  on('#flat', () => { P.flats = !P.flats; persist(); render() })
  on('#sdn', () => bump('size', -1, 10, 40)); on('#sup', () => bump('size', 1, 10, 40))
  on('#sl', () => bump('speed', -1, 1, 30)); on('#sf', () => bump('speed', 1, 1, 30))
  on('#play', () => scroller.toggle())
  on('#cols', () => { colsPref = !colsPref; store.set('chords:cols', colsPref ? '1' : '0'); scroller.stop(); scrollTo(0, 0); page = 0; render() })
  on('#pprev', () => goPage(page - 1)); on('#pnext', () => goPage(page + 1))

  // tap the left or right half of the sheet, or swipe, to turn the page
  sheet.addEventListener('click', e => {
    if (!colsOn()) return
    const r = sheet.getBoundingClientRect()
    goPage(page + (e.clientX < r.left + r.width / 2 ? -1 : 1))
  })
  let x0 = null
  sheet.addEventListener('touchstart', e => { scroller.stop(); x0 = e.touches[0].clientX }, { passive: true })
  sheet.addEventListener('touchend', e => {
    if (x0 !== null && colsOn()) { const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 50) goPage(page + (dx < 0 ? 1 : -1)) }
    x0 = null
  })
  addEventListener('resize', () => { render() })

  addEventListener('keydown', e => {
    if (!P || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.metaKey || e.ctrlKey || e.altKey || document.querySelector('dialog[open]')) return
    const k = e.key
    if (colsOn()) {
      if (k === 'ArrowRight' || k === 'PageDown' || k === ' ') { e.preventDefault(); goPage(page + 1) }
      else if (k === 'ArrowLeft' || k === 'PageUp') { e.preventDefault(); goPage(page - 1) }
    } else if (k === ' ') { if (e.target.tagName === 'BUTTON') return; e.preventDefault(); scroller.toggle() }
    if (k === '+' || k === '=') bump('speed', 1, 1, 30)
    else if (k === '-') bump('speed', -1, 1, 30)
    else if (k === ']' && doc.transposable) bump('t', 1, -11, 11)
    else if (k === '[' && doc.transposable) bump('t', -1, -11, 11)
  })

  // Capo finder: suggests a transposition and capo that give easier shapes.
  on('#finder', () => {
    const panel = $('#fpanel')
    if (!panel.hidden) { panel.hidden = true; return }
    const { current, options } = suggestCapo(doc.body, { flats: P.flats })
    if (!current) { panel.textContent = 'No chords found in this song.'; panel.hidden = false; return }
    const pct = n => Math.round(n * 100) + '% easy'
    panel.innerHTML = `<p class="muted">Now: ${esc(current.chords.join(' '))} (${pct(current.easy)})</p>` + options.map(o =>
      `<button data-t="${o.t}" data-c="${o.c}">${o.c ? `Capo ${o.c}` : 'No capo'}${o.t ? `, ${o.t > 0 ? '+' : ''}${o.t} semitones` : ''}: ${esc(o.chords.join(' '))} (${pct(o.easy)})</button>`).join('')
    panel.hidden = false
  })
  $('#fpanel').addEventListener('click', e => {
    const b = e.target.closest('button[data-t]')
    if (!b) return
    P.t = +b.dataset.t; P.c = +b.dataset.c
    persist(); $('#fpanel').hidden = true; render()
  })

  return {
    async load(newDoc, newPrefs) {
      scroller.stop()
      doc = newDoc; prefs = newPrefs; page = 0
      $('#fpanel').hidden = true
      const g = await prefs.load()
      P = { t: 0, c: 0, speed: 5, size: g.global?.size || 17, flats: doc.body ? usesFlats(doc.body) : false, ...g.prefs, ...g.force }
      scrollTo(0, 0)
      render()
    },
    state: () => P,
    stop: () => scroller.stop(),
  }
}
