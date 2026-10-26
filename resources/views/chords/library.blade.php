@extends('layouts.chords', ['pageScript' => 'resources/js/pages/library.js'])

@section('title', 'ThunderChords')

@section('body')
<x-chords-shell nav="songs" :search-live="true" :show-categories="true">
  <div class="page-heading dash-heading">
    <div>
      <h1>My Songs</h1>
      <p class="subheading medhint">Your personal library of songs and chords.</p>
    </div>
    <button id="add" class="primary add-btn" data-needs-net>＋ Add Song</button>
  </div>

  <div id="stats" class="stats" aria-live="polite"></div>

  <section class="library" aria-label="Song library">
    <div class="library-tools filters">
      <div id="viewtabs" class="viewtabs tabs" role="tablist">
        <button type="button" class="tab on" data-view="all" role="tab" aria-selected="true">All</button>
        <button type="button" class="tab" data-view="added" role="tab" aria-selected="false">Recently Added</button>
        <button type="button" class="tab" data-view="opened" role="tab" aria-selected="false">Recently Opened</button>
      </div>
      <div class="filter-right">
        <label class="sort-label">
          <select id="sort" class="sort" aria-label="Sort songs">
            <option value="artist">Sort by: Artist</option>
            <option value="title">Sort by: Title</option>
            <option value="added">Sort by: Recently Added</option>
            <option value="opened">Sort by: Recently Opened</option>
            <option value="key">Sort by: Key</option>
          </select>
        </label>
        <label class="sort-label">
          <select id="kind" class="sort" aria-label="Show songs or medleys">
            <option value="all">Songs and medleys</option>
            <option value="song">Songs</option>
            <option value="medley">Medleys</option>
          </select>
        </label>
      </div>
    </div>

    <p class="muted medhint pick-hint" style="padding: 10px 16px 0">Tick songs to build a medley (2+).</p>
    <div id="selbar" class="selbar" hidden><span id="selcount"></span><button id="mk" class="primary">Make medley</button><button id="selclear" class="btn">Clear</button></div>
    <ul id="list" class="list songlist"></ul>
    <p id="empty" class="muted" hidden style="padding: 16px">Nothing here yet. Choose Add Song and paste a link.</p>
    <div class="library-foot foot">
      <button id="offline-save" class="btn">Save for offline</button>
      <span id="offline-msg" class="muted"></span>
    </div>
  </section>

  @slot('rail')
    <section class="rail-card panel-card">
      <h2 class="rail-h panel-heading">Quick Actions</h2>
      <button type="button" id="add2" class="qa quick-action" data-needs-net>
        <span class="qa-ico qa-green quick-icon green" aria-hidden="true">⊕</span>
        <span><b class="quick-title">Add Song</b><small class="quick-desc">Start a new song entry</small></span>
        <span class="chevron" aria-hidden="true">›</span>
      </button>
      <a href="/setlists" class="qa quick-action">
        <span class="qa-ico qa-blue quick-icon blue" aria-hidden="true">☰</span>
        <span><b class="quick-title">Setlists</b><small class="quick-desc">Build a gig list</small></span>
        <span class="chevron" aria-hidden="true">›</span>
      </a>
      <a href="/medley" class="qa quick-action" data-needs-net>
        <span class="qa-ico qa-purple quick-icon purple" aria-hidden="true">♫</span>
        <span><b class="quick-title">Medleys</b><small class="quick-desc">Combine songs</small></span>
        <span class="chevron" aria-hidden="true">›</span>
      </a>
    </section>
    <section class="rail-card panel-card">
      <h2 class="rail-h panel-heading">Recently Opened</h2>
      <ul id="recent" class="recent"></ul>
      <p id="recent-empty" class="muted text-sm">Open a song to see it here.</p>
    </section>
    <section class="rail-card tip-card tip">
      <h2 class="rail-h">Practice tip</h2>
      <p class="tip-text">Use the capo setting to quickly transpose a song into a more comfortable key.</p>
    </section>
  @endslot
</x-chords-shell>

<dialog id="dlg">
  <form id="addform" class="addform">
    <header class="dlghead">
      <h2>Add song</h2>
      <button type="button" id="close" class="iconbtn" aria-label="Close">✕</button>
    </header>

    <div class="dlgbody">
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
      </div>

      <div id="step-preview" hidden>
        <p id="dupnote" class="warn" hidden></p>
        <div class="row"><input id="ftitle" placeholder="Title" autocomplete="off"><input id="fartist" placeholder="Artist" autocomplete="off"></div>
        <div class="row">
          <label class="field">Key <input id="fkey" placeholder="e.g. Am" autocomplete="off"><span id="fkeyhint" class="muted" hidden>guessed</span></label>
          <label class="field">Capo <input id="fcapo" type="number" min="0" max="12" placeholder="0"></label>
        </div>
        <label id="fsourcewrap" class="field" hidden>Source <input id="fsource" type="url" autocomplete="off"></label>
        <div id="sheetprev" class="preview"></div>
        <div class="tagpick">
          <span class="tagpick-label">Categories</span>
          <div id="ftags-chips" class="tags"></div>
          <input id="ftags" type="text" placeholder="Add new (comma separated)" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="dlgfoot">
      <p id="err" class="err" role="alert"></p>
      <div class="dlgactions">
        <button type="submit" id="doPreview" class="primary" formnovalidate>Import</button>
        <button type="button" id="doBack" class="btn" hidden>Back</button>
        <a id="doOpen" class="btn primary" hidden>Open existing</a>
        <button type="submit" id="doSave" class="primary" hidden>Save song</button>
      </div>
    </div>
  </form>
</dialog>
@endsection
