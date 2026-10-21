// Everything site-specific lives here. If Ultimate Guitar changes its page, this is the only file to fix.

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'

const ent = s => s
  .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
  .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(+d))
  .replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&lt;/g, '<')
  .replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&')

// Lines that sheet sites mix into the text. Exact-line matches only, so lyrics are safe.
const JUNK = /^\s*(advertisement|advertising|sponsored|print|transpose|autoscroll|chords:|tabs?:)\s*$/i

// Turn any chord sheet text into our plain format: chords on the line above the lyrics.
export function normalize(text) {
  return String(text)
    .replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ')
    .replace(/\[\/?ch\]/g, '').replace(/\[\/?tab\]/g, '')
    .replace(/\u266f/g, '#').replace(/\u266d/g, 'b')
    .split('\n').filter(l => !JUNK.test(l)).join('\n')
    .replace(/[ \t]+$/gm, '').replace(/\n{3,}/g, '\n\n')
    .replace(/^\n+|\n+$/g, '') + '\n'
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
  return {
    title: t.song_name,
    artist: t.artist_name,
    key: meta.tonality || t.tonality_name || '',
    capo: meta.capo ?? t.capo ?? 0,
    body: normalize(content),
  }
}

// Best effort for other chord sites: take the biggest <pre> block on the page.
function generic(html) {
  const best = [...html.matchAll(/<pre[^>]*>([\s\S]*?)<\/pre>/gi)].map(m => m[1]).sort((a, b) => b.length - a.length)[0]
  if (!best || best.length < 80) return null
  const text = ent(best.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, ''))
  const raw = (html.match(/<meta[^>]+property="og:title"[^>]+content="([^"]*)"/i) || html.match(/<title>([^<]*)<\/title>/i) || [])[1] || ''
  return { title: ent(raw).replace(/\s*(chords|tab|lyrics)\b.*$/i, '').trim(), artist: '', key: '', capo: 0, body: normalize(text) }
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
