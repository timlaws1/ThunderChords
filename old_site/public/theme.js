// Early theme boot + toggle. Load as a classic script in <head> before paint.
(function () {
  const KEY = 'tc-theme'
  const COLORS = { dark: '#12171f', light: '#ebe6dc' }

  const system = () => (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')

  function resolve() {
    try {
      const s = localStorage.getItem(KEY)
      if (s === 'light' || s === 'dark') return s
    } catch {}
    return system()
  }

  function applyTheme(t, persist) {
    const theme = t === 'light' || t === 'dark' ? t : resolve()
    document.documentElement.dataset.theme = theme
    const m = document.querySelector('meta[name="theme-color"]')
    if (m) m.content = COLORS[theme]
    for (const b of document.querySelectorAll('[data-theme-toggle]')) {
      b.textContent = theme === 'dark' ? 'Light' : 'Dark'
      b.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme')
    }
    if (persist) try { localStorage.setItem(KEY, theme) } catch {}
    return theme
  }

  function cycleTheme() {
    const now = document.documentElement.dataset.theme || resolve()
    return applyTheme(now === 'dark' ? 'light' : 'dark', true)
  }

  applyTheme(resolve(), false)
  document.addEventListener('DOMContentLoaded', () => applyTheme(resolve(), false))

  document.addEventListener('click', e => {
    if (e.target.closest('[data-theme-toggle]')) cycleTheme()
  })

  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    try { if (localStorage.getItem(KEY)) return } catch { return }
    applyTheme(system(), false)
  })

  window.tcTheme = { applyTheme, cycleTheme, resolve }
})()
