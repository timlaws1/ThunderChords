@extends('layouts.chords', ['pageScript' => 'resources/js/pages/medley.js'])

@section('title', 'Medley · ThunderChords')

@section('body')
<x-chords-shell nav="songs">
  <div class="page-heading">
    <div class="ttl" style="flex:1;min-width:0">
      <input id="name" class="ttl" placeholder="Medley name" aria-label="Medley name" style="width:100%;font-size:29px;font-weight:750;letter-spacing:-.9px;border:0;background:transparent;padding:0;border-radius:0">
    </div>
    <div class="row" style="margin:0">
      <button id="save" class="primary" data-needs-net>Save</button>
      <a id="play" class="btn" hidden>Play</a>
      <button id="share" class="btn" data-needs-net hidden>Share</button>
    </div>
  </div>

  <section class="page-card">
    <div class="filters" style="margin-bottom:12px">
      <label class="sort-label">Target key <input id="tkey" list="keys" placeholder="e.g. G or Am" size="8"></label>
      <div class="filter-right">
        <button id="match" class="btn">Match keys</button>
        <button id="order" class="btn">Suggest order</button>
        <span id="status" class="muted"></span>
      </div>
    </div>
    <datalist id="keys"></datalist>
    <p class="muted text-sm">Tick sections, or Edit text to shorten a song for this medley only — the library copy stays unchanged. Keys marked "guessed" come from the first chord.</p>
    <ul id="items" class="items"></ul>
    <p id="empty" class="muted" hidden>No songs yet. Search below to add some.</p>
  </section>

  <section id="suggest" class="page-card" hidden>
    <h2 class="rail-h" style="margin-bottom:6px">How about adding these tracks?</h2>
    <p class="muted text-sm">From your library, based on genre, artist, and key.</p>
    <ul id="suggest-list" class="list"></ul>
  </section>

  <section class="page-card">
    <h2 class="rail-h" style="margin-bottom:10px">Add songs</h2>
    <input id="sq" type="search" placeholder="Search your library" autocomplete="off" style="width:100%">
    <ul id="results" class="list"></ul>
  </section>

  <section class="page-card">
    <h2 class="rail-h" style="margin-bottom:10px">Preview</h2>
    <div id="preview" class="preview"></div>
  </section>
</x-chords-shell>

<dialog id="edlg" class="wide">
  <h2 id="edtitle">Edit text</h2>
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
