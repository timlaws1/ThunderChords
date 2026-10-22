@extends('layouts.chords', ['pageScript' => 'resources/js/pages/setlist.js'])

@section('title', 'Setlist · ThunderChords')

@section('body')
<x-chords-shell nav="setlists">
  <div class="page-heading">
    <div class="ttl" style="flex:1;min-width:0">
      <input id="name" class="ttl" aria-label="Setlist name" style="width:100%;font-size:29px;font-weight:750;letter-spacing:-.9px;border:0;background:transparent;padding:0;border-radius:0">
    </div>
    <div class="row" style="margin:0">
      <a id="play" class="btn primary">Play</a>
      <button id="share" class="btn" data-needs-net>Share</button>
    </div>
  </div>

  <section class="page-card">
    <p class="muted text-sm"><span id="status">Loading...</span> Drag the handle or use the arrows to reorder. T is transpose and C is capo for this setlist only.</p>
    <ul id="items" class="items"></ul>
    <p id="empty" class="muted" hidden>No songs yet. Search below to add some.</p>
  </section>

  <section class="page-card">
    <h2 class="rail-h" style="margin-bottom:10px">Add songs</h2>
    <input id="sq" type="search" class="w-full" placeholder="Search your library" autocomplete="off" style="width:100%">
    <ul id="results" class="list"></ul>
  </section>
</x-chords-shell>
@endsection
