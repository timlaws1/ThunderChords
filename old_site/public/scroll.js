// Autoscroll. Fractional pixels are accumulated because browsers drop sub-pixel scrollBy values.
export function createScroller({ getSpeed, onState = () => {} }) {
  let playing = false, last = 0, acc = 0, lock = null
  const wake = async on => {
    try { if (on) lock = await navigator.wakeLock?.request('screen'); else { await lock?.release(); lock = null } } catch {}
  }
  function tick(now) {
    if (!playing) return
    acc += getSpeed() * Math.min(now - last, 100) / 1000
    last = now
    const px = Math.floor(acc)
    if (px) { scrollBy(0, px); acc -= px }
    if (innerHeight + scrollY >= document.documentElement.scrollHeight - 2) return stop()
    requestAnimationFrame(tick)
  }
  function play() { if (playing) return; playing = true; last = performance.now(); acc = 0; requestAnimationFrame(tick); wake(true); onState(true) }
  function stop() { if (!playing) return; playing = false; wake(false); onState(false) }
  addEventListener('wheel', stop, { passive: true })
  addEventListener('touchstart', stop, { passive: true })
  document.addEventListener('visibilitychange', () => { if (document.hidden) stop() })
  return { play, stop, toggle: () => (playing ? stop() : play()), get playing() { return playing } }
}
