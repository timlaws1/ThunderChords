@extends('layouts.chords', ['pageScript' => 'resources/js/pages/library.js'])

@section('title', 'ThunderChords')

@section('body')
<div class="dash">
  <aside class="dash-side" aria-label="Library navigation">
    <div class="dash-brand">
      <a href="/" class="brand">ThunderChords</a>
    </div>
    <nav class="dash-nav">
      <a href="/" class="dash-link on" aria-current="page">Songs</a>
      <a href="/setlists" class="dash-link">Setlists</a>
      <a href="/account" class="dash-link" data-needs-net>Account</a>
      <button type="button" class="dash-link theme-btn" data-theme-toggle>Dark</button>
    </nav>
    <div class="dash-cats">
      <h2 class="dash-h">Categories</h2>
      <div id="tags" class="catlist"></div>
    </div>
  </aside>

  <div class="dash-main">
    <header class="dash-top">
      <input id="q" type="search" class="dash-search" placeholder="Search title, artist or lyrics" autocomplete="off">
      <button id="add" class="primary" data-needs-net>Add song</button>
    </header>

    <main class="dash-content">
      <div class="dash-heading">
        <h1 class="font-brand">My Songs</h1>
        <p class="muted medhint">Tick songs to build a medley (2+).</p>
      </div>

      <div id="stats" class="stats" aria-live="polite"></div>

      <div class="filters">
        <div id="viewtabs" class="viewtabs" role="tablist">
          <button type="button" class="chip on" data-view="all" role="tab" aria-selected="true">All</button>
          <button type="button" class="chip" data-view="added" role="tab" aria-selected="false">Recently added</button>
          <button type="button" class="chip" data-view="opened" role="tab" aria-selected="false">Recently opened</button>
        </div>
        <label class="flex items-center gap-2 text-sm text-mute">Sort
          <select id="sort" class="rounded-lg border border-line bg-panel px-2 py-1.5 text-ink">
            <option value="artist">Artist</option>
            <option value="title">Title</option>
            <option value="added">Newest first</option>
            <option value="opened">Recently opened</option>
            <option value="key">Key</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm text-mute">Show
          <select id="kind" class="rounded-lg border border-line bg-panel px-2 py-1.5 text-ink">
            <option value="all">Songs and medleys</option>
            <option value="song">Songs</option>
            <option value="medley">Medleys</option>
          </select>
        </label>
      </div>

      <div id="selbar" class="selbar" hidden><span id="selcount"></span><button id="mk" class="primary">Make medley</button><button id="selclear" class="btn">Clear</button></div>
      <ul id="list" class="list songlist"></ul>
      <p id="empty" class="muted" hidden>Nothing here yet. Choose Add song and paste a link.</p>
      <p class="foot flex flex-wrap items-center gap-3"><button id="offline-save" class="btn">Save for offline</button> <span id="offline-msg" class="muted"></span></p>
    </main>
  </div>

  <aside class="dash-rail" aria-label="Quick actions">
    <section class="rail-card">
      <h2 class="dash-h">Quick actions</h2>
      <button type="button" id="add2" class="primary rail-btn" data-needs-net>Add song</button>
      <a href="/setlists" class="btn rail-btn">Setlists</a>
    </section>
    <section class="rail-card">
      <h2 class="dash-h">Recently opened</h2>
      <ul id="recent" class="recent"></ul>
      <p id="recent-empty" class="muted text-sm">Open a song to see it here.</p>
    </section>
  </aside>
</div>

<dialog id="dlg">
  <form id="addform" class="addform">
    <header class="dlghead">
      <h2 class="font-brand">Add song</h2>
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
