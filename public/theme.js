// Force the light mockup palette. Kept as an early <head> script for theme-color and offline caches.
(function () {
  const COLOR = '#f5f7fb'

  function applyLight() {
    document.documentElement.dataset.theme = 'light'
    const m = document.querySelector('meta[name="theme-color"]')
    if (m) m.content = COLOR
  }

  applyLight()
  document.addEventListener('DOMContentLoaded', applyLight)

  window.tcTheme = {
    applyTheme: applyLight,
    cycleTheme: applyLight,
    resolve: () => 'light',
  }
})()
