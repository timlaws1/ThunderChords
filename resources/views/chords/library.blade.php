@extends('layouts.chords', ['pageScript' => 'resources/js/pages/library.js'])

@section('title', 'ThunderChords')

@section('body')
@php
  $user = Auth::user();
  $initial = strtoupper(mb_substr($user->name ?: $user->email, 0, 1));
@endphp
<div class="dash">
  <aside class="dash-side" id="dash-side" aria-label="Library navigation">
    <div class="dash-brand">
      <a href="/" class="brand" aria-label="ThunderChords home">
        <span class="brand-mark" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c-1.2 2.4-4 4.2-4 8a4 4 0 0 0 8 0c0-3.8-2.8-5.6-4-8zm-1 14.9V22h2v-5.1A6 6 0 0 1 6 11h2a4 4 0 0 0 3 5.9z"/></svg>
        </span>
        ThunderChords
      </a>
      <button type="button" class="nav-close iconbtn" id="nav-close" aria-label="Close menu">&times;</button>
    </div>

    <nav class="dash-nav">
      <a href="/" class="dash-link on" aria-current="page">
        <span class="dash-ico" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>
        Songs
      </a>
      <a href="/setlists" class="dash-link">
        <span class="dash-ico" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></span>
        Setlists
      </a>
    </nav>

    <div class="dash-section">
      <div class="dash-section-head">
        <h2 class="dash-h">Setlists</h2>
        <a href="/setlists" class="dash-add" title="Manage setlists" aria-label="Manage setlists">+</a>
      </div>
      <div id="setlists-nav" class="catlist"></div>
    </div>

    <div class="dash-section">
      <h2 class="dash-h">Categories</h2>
      <div id="tags" class="catlist"></div>
    </div>

    <div class="dash-foot">
      <a href="/account" class="dash-link" data-needs-net>
        <span class="dash-ico" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span>
        Settings
      </a>
    </div>
  </aside>

  <div class="dash-scrim" id="dash-scrim" hidden></div>

  <div class="dash-main">
    <header class="dash-top">
      <button type="button" class="nav-toggle btn" id="nav-toggle" aria-label="Open menu" aria-controls="dash-side" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <input id="q" type="search" class="dash-search" placeholder="Search songs, artists, or lyrics..." autocomplete="off">
      <button type="button" class="theme-icon btn" data-theme-toggle aria-label="Toggle theme">
        <svg class="ico-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/></svg>
      </button>
      <details class="user-menu">
        <summary class="user-summary">
          <span class="avatar" aria-hidden="true">{{ $initial }}</span>
          <span class="user-name">{{ $user->name }}</span>
        </summary>
        <div class="user-panel">
          <a href="/account" data-needs-net>Account</a>
          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="user-logout">Sign out</button>
          </form>
        </div>
      </details>
    </header>

    <main class="dash-content">
      <div class="dash-heading">
        <div>
          <h1>My Songs</h1>
          <p class="muted medhint">Your personal library of songs and chords.</p>
        </div>
        <button id="add" class="primary add-btn" data-needs-net>+ Add Song</button>
      </div>

      <div id="stats" class="stats" aria-live="polite"></div>

      <div class="filters">
        <div id="viewtabs" class="viewtabs" role="tablist">
          <button type="button" class="tab on" data-view="all" role="tab" aria-selected="true">All</button>
          <button type="button" class="tab" data-view="added" role="tab" aria-selected="false">Recently added</button>
          <button type="button" class="tab" data-view="opened" role="tab" aria-selected="false">Recently opened</button>
        </div>
        <div class="filter-right">
          <label class="sort-label">Sort by
            <select id="sort">
              <option value="artist">Artist</option>
              <option value="title">Title</option>
              <option value="added">Recently added</option>
              <option value="opened">Recently opened</option>
              <option value="key">Key</option>
            </select>
          </label>
          <label class="sort-label">Show
            <select id="kind">
              <option value="all">Songs and medleys</option>
              <option value="song">Songs</option>
              <option value="medley">Medleys</option>
            </select>
          </label>
        </div>
      </div>

      <p class="muted medhint pick-hint">Tick songs to build a medley (2+).</p>
      <div id="selbar" class="selbar" hidden><span id="selcount"></span><button id="mk" class="primary">Make medley</button><button id="selclear" class="btn">Clear</button></div>
      <ul id="list" class="list songlist"></ul>
      <p id="empty" class="muted" hidden>Nothing here yet. Choose Add Song and paste a link.</p>
      <p class="foot flex flex-wrap items-center gap-3"><button id="offline-save" class="btn">Save for offline</button> <span id="offline-msg" class="muted"></span></p>
    </main>
  </div>

  <aside class="dash-rail" aria-label="Quick actions">
    <section class="rail-card">
      <h2 class="rail-h">Quick Actions</h2>
      <button type="button" id="add2" class="qa" data-needs-net>
        <span class="qa-ico qa-green" aria-hidden="true">+</span>
        <span><b>Add Song</b><small>Import a chord sheet</small></span>
      </button>
      <a href="/setlists" class="qa">
        <span class="qa-ico qa-blue" aria-hidden="true">☰</span>
        <span><b>Setlists</b><small>Build a gig list</small></span>
      </a>
      <a href="/medley" class="qa" data-needs-net>
        <span class="qa-ico qa-purple" aria-hidden="true">♫</span>
        <span><b>Medleys</b><small>Combine songs</small></span>
      </a>
    </section>
    <section class="rail-card">
      <h2 class="rail-h">Recently Opened</h2>
      <ul id="recent" class="recent"></ul>
      <p id="recent-empty" class="muted text-sm">Open a song to see it here.</p>
    </section>
    <section class="rail-card tip-card">
      <h2 class="rail-h">Practice Tip</h2>
      <p class="tip-text">Use the capo tool on a song page to transpose to a more comfortable key without rewriting the chart.</p>
    </section>
  </aside>
</div>

<dialog id="dlg">
  <form id="addform" class="addform">
    <header class="dlghead">
      <h2>Add song</h2>
      <button type="button" id="close" class="iconbtn" aria-label="Close">✕</button>
    </header>

    <div id="step-input">
      <div class="modes" role="tablist">
        <button type="button" class="mode on" data-mode="link" role="tab" aria-selected="true">Link</button>
        <button type="button" class="mode" data-mode="paste" role="tab" aria-selected="false">Paste</button>
      </div>
      <div id="mode-link" class="modepane">
        <input id="url" type="url" placeholder="https://tabs.ultimate-guitar.com/..." autocomplete="off">
      </div>
      <div id="mode-paste" class="modepane" hidden>
        <div class="row"><input id="ptitle" placeholder="Title" autocomplete="off"><input id="partist" placeholder="Artist" autocomplete="off"></div>
        <textarea id="text" placeholder="Paste the chord sheet here" spellcheck="false"></textarea>
      </div>
      <div class="dlgactions">
        <button type="submit" id="doPreview" class="primary" formnovalidate>Import</button>
      </div>
    </div>

    <div id="step-preview" hidden>
      <p id="dupnote" class="warn" hidden></p>
      <div class="row"><input id="ftitle" placeholder="Title" autocomplete="off"><input id="fartist" placeholder="Artist" autocomplete="off"></div>
      <div class="row">
        <label class="field">Key <input id="fkey" placeholder="e.g. Am" autocomplete="off"><span id="fkeyhint" class="muted" hidden>guessed</span></label>
        <label class="field">Capo <input id="fcapo" type="number" min="0" max="12" placeholder="0"></label>
      </div>
      <div class="tagpick">
        <span class="tagpick-label">Categories</span>
        <div id="ftags-chips" class="tags"></div>
        <input id="ftags" type="text" placeholder="Add new (comma separated)" autocomplete="off">
      </div>
      <label id="fsourcewrap" class="field" hidden>Source <input id="fsource" type="url" autocomplete="off"></label>
      <div id="sheetprev" class="preview"></div>
      <div class="dlgactions">
        <button type="button" id="doBack" class="btn">Back</button>
        <a id="doOpen" class="btn primary" hidden>Open existing</a>
        <button type="submit" id="doSave" class="primary">Save song</button>
      </div>
    </div>

    <p id="err" class="err" role="alert"></p>
  </form>
</dialog>
@endsection
