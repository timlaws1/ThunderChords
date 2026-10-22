@extends('layouts.chords', ['pageScript' => 'resources/js/pages/medley.js'])

@section('title', 'Medley · ThunderChords')

@section('body')
<header class="bar">
  <a href="/" class="btn">Library</a>
  <input id="name" class="ttl rounded-lg border border-line bg-panel px-3 py-2" placeholder="Medley name" aria-label="Medley name">
  <button id="save" class="primary" data-needs-net>Save</button>
  <a id="play" class="btn" hidden>Play</a>
  <button id="share" class="btn" data-needs-net hidden>Share</button>
  <button type="button" class="theme-btn btn" data-theme-toggle>Dark</button>
</header>
<main class="wrap">
  <div class="filters">
    <label class="flex items-center gap-2 text-sm text-mute">Target key <input id="tkey" list="keys" placeholder="e.g. G or Am" size="8" class="rounded-lg border border-line bg-panel px-2 py-1.5 text-ink"></label>
    <button id="match" class="btn">Match keys</button>
    <button id="order" class="btn">Suggest order</button>
    <span id="status" class="muted"></span>
  </div>
  <datalist id="keys"></datalist>
  <p class="muted text-sm">Tick sections, or Edit text to shorten a song for this medley only — the library copy stays unchanged. Keys marked "guessed" come from the first chord.</p>
  <ul id="items" class="items"></ul>
  <p id="empty" class="muted" hidden>No songs yet. Search below to add some.</p>
  <h2 class="font-brand text-[1.1rem] font-bold">Add songs</h2>
  <input id="sq" type="search" class="mt-1 w-full rounded-lg border border-line bg-panel px-3 py-2" placeholder="Search your library" autocomplete="off">
  <ul id="results" class="list"></ul>
  <h2 class="font-brand text-[1.1rem] font-bold">Preview</h2>
  <div id="preview" class="preview"></div>
</main>

<dialog id="edlg" class="wide">
  <h2 id="edtitle" class="font-brand">Edit text</h2>
  <p class="muted">Only used in this medley. The original song in your library is not changed.</p>
  <textarea id="ebody" spellcheck="false"></textarea>
  <p id="eerr" class="err"></p>
  <div class="dlgactions">
    <button type="button" id="ereset" class="btn">Use original song</button>
    <button type="button" id="eclose" class="btn">Cancel</button>
    <button type="button" id="eok" class="primary">Use this text</button>
  </div>
</dialog>
@endsection
