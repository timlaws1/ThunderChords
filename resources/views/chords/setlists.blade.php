@extends('layouts.chords', ['pageScript' => 'resources/js/pages/setlists.js'])

@section('title', 'Setlists · ThunderChords')

@section('body')
<x-chords-shell nav="setlists">
  <div class="page-heading">
    <div>
      <h1>Setlists</h1>
      <p class="subheading">Build gig lists from your library.</p>
    </div>
  </div>

  <section class="page-card">
    <div class="row"><input id="name" placeholder="New setlist name"><button id="make" class="primary" data-needs-net>Create</button></div>
    <p id="err" class="err"></p>
    <ul id="list" class="list"></ul>
    <p id="empty" class="muted" hidden>No setlists yet. Name one above to start.</p>
  </section>
</x-chords-shell>
@endsection
