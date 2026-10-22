// Reordering for lists of <li data-i="n"> rows. Two ways, both always available:
//   up/down buttons (.up, .down) and drag and drop by a handle (.grip), done with pointer events so it works on touch.
export function moveItem(arr, from, to) {
  if (from === to || from < 0 || to < 0 || from >= arr.length || to >= arr.length) return arr
  const a = arr.slice()
  a.splice(to, 0, a.splice(from, 1)[0])
  return a
}

export function enableReorder(list, move) {
  list.addEventListener('click', e => {
    const b = e.target.closest('.up,.down')
    if (!b || !list.contains(b)) return
    const i = +b.closest('li').dataset.i
    move(i, i + (b.classList.contains('up') ? -1 : 1))
  })
  let from = null, over = null
  const clear = () => list.querySelectorAll('.over,.drag').forEach(x => x.classList.remove('over', 'drag'))
  list.addEventListener('pointerdown', e => {
    const g = e.target.closest('.grip')
    if (!g) return
    from = +g.closest('li').dataset.i
    g.setPointerCapture(e.pointerId)
    g.closest('li').classList.add('drag')
    e.preventDefault()
  })
  list.addEventListener('pointermove', e => {
    if (from === null) return
    list.querySelectorAll('.over').forEach(x => x.classList.remove('over'))
    const li = document.elementFromPoint(e.clientX, e.clientY)?.closest('li')
    over = li && list.contains(li) ? +li.dataset.i : null
    if (over !== null) li.classList.add('over')
    if (e.clientY < 70) scrollBy(0, -12)
    else if (e.clientY > innerHeight - 70) scrollBy(0, 12)
  })
  list.addEventListener('pointerup', () => {
    const [f, o] = [from, over]
    from = over = null
    clear()
    if (f !== null && o !== null && o !== f) move(f, o)
  })
  list.addEventListener('pointercancel', () => { from = over = null; clear() })
}
