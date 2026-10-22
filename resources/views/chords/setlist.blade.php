@extends('layouts.chords', ['pageScript' => 'resources/js/pages/setlist.js'])

@section('title', 'Setlist · ThunderChords')

@section('body')
<header class="bar">
  <a href="/setlists" class="btn">Setlists</a>
  <input id="name" class="ttl rounded-lg border border-line bg-panel px-3 py-2" aria-label="Setlist name">
  <a id="play" class="btn primary">Play</a>
  <button id="share" class="btn" data-needs-net>Share</button>
  <button type="button" class="theme-btn btn" data-theme-toggle>Dark</button>
</header>
<main class="wrap">
  <p class="muted text-sm"><span id="status">Loading...</span> Drag the handle or use the arrows to reorder. T is transpose and C is capo for this setlist only.</p>
  <ul id="items" class="items"></ul>
  <p id="empty" class="muted" hidden>No songs yet. Search below to add some.</p>
  <h2 class="font-brand text-[1.1rem] font-bold">Add songs</h2>
  <input id="sq" type="search" class="mt-1 w-full rounded-lg border border-line bg-panel px-3 py-2" placeholder="Search your library" autocomplete="off">
  <ul id="results" class="list"></ul>
</main>
@endsection
