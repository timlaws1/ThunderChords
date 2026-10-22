@extends('layouts.chords', ['pageScript' => 'resources/js/pages/setlists.js'])

@section('title', 'Setlists · ThunderChords')

@section('body')
<header class="bar">
  <a href="/" class="btn">Library</a>
  <h1 class="font-brand text-[1.45rem] font-bold tracking-tight">Setlists</h1>
  <a href="/account" class="btn ml-auto" data-needs-net>Account</a>
  <button type="button" class="theme-btn btn" data-theme-toggle>Dark</button>
</header>
<main class="wrap">
  <div class="row"><input id="name" placeholder="New setlist name"><button id="make" class="primary" data-needs-net>Create</button></div>
  <p id="err" class="err"></p>
  <ul id="list" class="list"></ul>
  <p id="empty" class="muted" hidden>No setlists yet. Name one above to start.</p>
</main>
@endsection
