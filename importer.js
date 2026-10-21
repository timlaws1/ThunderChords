// Everything site-specific lives here. If Ultimate Guitar changes its page, this is the only file to fix.

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'

const ent = s => s
  .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
  .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(+d))
  .replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&lt;/g, '<')
  .replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&')

// Lines that sheet sites mix into the text. Exact-line matches only, so lyrics are safe.
const JUNK = /^\s*(advertisement|advertising|sponsored|print|transpose|autoscroll|chords:|tabs?:)\s*$/i
const TAB = /^\s*[a-gA-G][#b]?\s*[|:]\s*[-\dhpbrx/\\~^()|*.\s<>]{3,}$/
const SECTION = /^\s*\[[^\]]+\]\s*$/
const CHORD_TOK = /^([A-G][#b]?)((?:maj|min|m|M|dim|aug|sus|add|no|[0-9+°ø\-#b()])*)(?:\/([A-G][#b]?))?$/
const INLINE = /\[ch\]([^[\]]+)\[\/ch\]|\[([^\]]+)\]/gi

const isChordTok = s => CHORD_TOK.test(String(s).trim())

// Lift UG [ch]…[/ch] and ChordPro [Am] markers into a chord line above the lyrics.
function liftLine(line) {
  if (TAB.test(line)) return [line]
  if (SECTION.test(line)) {
    const inner = line.trim().slice(1, -1).trim()
    return isChordTok(inner) ? [inner] : [line]
  }

  const matches = []
  INLINE.lastIndex = 0
  for (let m; (m = INLINE.exec(line));) {
    const tok = (m[1] ?? m[2]).trim()
    if (!isChordTok(tok)) continue
    matches.push({ start: m.index, end: m.index + m[0].length, chord: tok })
  }
  if (!matches.length) return [line]

  let lyric = '', last = 0
  const places = []
  for (const mk of matches) {
    lyric += line.slice(last, mk.start)
    places.push({ chord: mk.chord, col: lyric.length })
    last = mk.end
  }
  lyric += line.slice(last)

  if (!lyric.trim()) {
    let only = '', at = 0
    for (const mk of matches) {
      only += line.slice(at, mk.start) + mk.chord
      at = mk.end
    }
    return [(only + line.slice(at)).replace(/[ \t]+$/g, '')]
  }

  let chords = ''
  for (const { chord, col } of places) {
    const at = chords.length > col ? chords.length + 1 : col
    chords += ' '.repeat(at - chords.length) + chord
  }
  return [chords, lyric.replace(/[ \t]+$/g, '')]
}

function extractHeaders(lines) {
  let key = '', capo = 0, i = 0
  while (i < lines.length) {
    const l = lines[i]
    if (!l.trim()) { i++; continue }
    const km = l.match(/^\s*key\s*:\s*(.+?)\s*$/i)
    if (km) { key = km[1].trim(); i++; continue }
    const cm = l.match(/^\s*capo\s*:?\s*(\d+)\s*$/i)
    if (cm) { capo = +cm[1]; i++; continue }
    break
  }
  return { key, capo, lines: lines.slice(i) }
}

// Turn any chord sheet text into our plain format: chords on the line above the lyrics.
export function parseSheet(text) {
  const cleaned = String(text)
    .replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ')
    .replace(/\[\/?tab\]/gi, '')
    .replace(/\u266f/g, '#').replace(/\u266d/g, 'b')
  const lifted = cleaned.split('\n').filter(l => !JUNK.test(l)).flatMap(liftLine)
  const { key, capo, lines } = extractHeaders(lifted)
  const body = lines.join('\n').replace(/[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n').replace(/^\n+|\n+$/g, '') + '\n'
  return { body, key, capo }
}

export function normalize(text) {
  return parseSheet(text).body
}

function ultimateGuitar(html) {
  const tag = html.match(/<div[^>]*js-store[^>]*>/)?.[0]
  const raw = tag?.match(/data-content="([^"]*)"/)?.[1]
  if (!raw) return null
  let data
  try { data = JSON.parse(ent(raw)).store.page.data } catch { return null }
  const content = data?.tab_view?.wiki_tab?.content
  if (!content) return null
  const t = data.tab || {}, meta = data.tab_view.meta || {}
  const parsed = parseSheet(content)
  return {
    title: t.song_name,
    artist: t.artist_name,
    key: meta.tonality || t.tonality_name || parsed.key || '',
    capo: meta.capo ?? t.capo ?? parsed.capo ?? 0,
    body: parsed.body,
  }
}

// Best effort for other chord sites: take the biggest <pre> block on the page.
function generic(html) {
  const best = [...html.matchAll(/<pre[^>]*>([\s\S]*?)<\/pre>/gi)].map(m => m[1]).sort((a, b) => b.length - a.length)[0]
  if (!best || best.length < 80) return null
  const text = ent(best.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, ''))
  const raw = (html.match(/<meta[^>]+property="og:title"[^>]+content="([^"]*)"/i) || html.match(/<title>([^<]*)<\/title>/i) || [])[1] || ''
  const parsed = parseSheet(text)
  return {
    title: ent(raw).replace(/\s*(chords|tab|lyrics)\b.*$/i, '').trim(),
    artist: '',
    key: parsed.key || '',
    capo: parsed.capo || 0,
    body: parsed.body,
  }
}

export async function importFromUrl(url) {
  if (!/^https?:\/\//i.test(url)) throw new Error('That is not a web address.')
  const res = await fetch(url, {
    headers: { 'user-agent': UA, accept: 'text/html,*/*', 'accept-language': 'en-GB,en;q=0.9' },
    redirect: 'follow',
    signal: AbortSignal.timeout(20000),
  })
  if (!res.ok) throw new Error(`The site answered ${res.status}. Use the paste option instead.`)
  const html = await res.text()
  const song = ultimateGuitar(html) || generic(html)
  if (!song) throw new Error('Could not find a chord sheet on that page. Use the paste option instead.')
  return song
}
