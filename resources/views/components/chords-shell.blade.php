@props([
    'nav' => 'songs',
    'searchLive' => false,
    'showCategories' => false,
])

@php
    $user = Auth::user();
    $initial = strtoupper(mb_substr($user->name ?: $user->email, 0, 1));
    $q = request('q', '');
@endphp

<div {{ $attributes->class(['app']) }} id="app-shell">
  <aside class="sidebar dash-side" id="dash-side" aria-label="Main navigation">
    <div class="brand-row dash-brand">
      <a href="/" class="brand" aria-label="Chords home">
        <span class="brand-mark" aria-hidden="true">♩</span>
        Chords
      </a>
      <button type="button" class="nav-close iconbtn" id="nav-close" aria-label="Close menu">&times;</button>
    </div>

    <nav class="dash-nav nav" aria-label="Primary">
      <a href="/" class="dash-link{{ $nav === 'songs' ? ' on' : '' }}" @if($nav === 'songs') aria-current="page" @endif>
        <span class="dash-ico ico" aria-hidden="true">♫</span>
        <span class="nav-label">Songs</span>
      </a>
      <a href="/setlists" class="dash-link{{ $nav === 'setlists' ? ' on' : '' }}" @if($nav === 'setlists') aria-current="page" @endif>
        <span class="dash-ico ico" aria-hidden="true">▦</span>
        <span class="nav-label">Setlists</span>
      </a>
    </nav>

    <section class="dash-section side-section">
      <div class="dash-section-head side-heading">
        <h2 class="dash-h">Playlists</h2>
        <a href="/setlists" class="dash-add" title="Manage setlists" aria-label="Manage setlists">＋</a>
      </div>
      <div id="setlists-nav" class="catlist playlist" @if($showCategories) data-owned="1" @endif></div>
    </section>

    @if ($showCategories)
      <section class="dash-section side-section">
        <div class="dash-section-head side-heading">
          <h2 class="dash-h">Categories</h2>
        </div>
        <div id="tags" class="catlist playlist"></div>
      </section>
    @endif

    <div class="dash-foot side-bottom">
      <nav class="dash-nav nav">
        <a href="/account" class="dash-link{{ $nav === 'settings' ? ' on' : '' }}" data-needs-net @if($nav === 'settings') aria-current="page" @endif>
          <span class="dash-ico ico" aria-hidden="true">⚙</span>
          <span class="nav-label">Settings</span>
        </a>
      </nav>
    </div>
  </aside>

  <div class="dash-scrim" id="dash-scrim" hidden></div>

  <div class="main dash-main">
    <header class="topbar dash-top">
      <button type="button" class="nav-toggle btn" id="nav-toggle" aria-label="Open menu" aria-controls="dash-side" aria-expanded="false">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <label class="search-wrap">
        <span class="search-ico" aria-hidden="true">⌕</span>
        <input
          id="q"
          type="search"
          class="dash-search"
          placeholder="Search songs, artists, or chords..."
          autocomplete="off"
          value="{{ $q }}"
          @if($searchLive) data-search-live="1" @endif
          aria-label="Search songs, artists, or chords"
        >
      </label>
      <div class="top-actions">
        <details class="user-menu">
          <summary class="user-summary profile">
            <span class="avatar" aria-hidden="true">{{ $initial }}</span>
            <span class="user-name name">{{ $user->name }}</span>
            <span class="down" aria-hidden="true">⌄</span>
          </summary>
          <div class="user-panel">
            <a href="/account" data-needs-net>Account</a>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="user-logout">Sign out</button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <div @class(['content', 'dash-body', 'has-rail' => isset($rail)])>
      <div class="primary-col dash-content">
        {{ $slot }}
      </div>
      @isset($rail)
        <aside class="right dash-rail" aria-label="Quick actions">
          {{ $rail }}
        </aside>
      @endisset
    </div>
  </div>
</div>
