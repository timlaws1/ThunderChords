@extends('layouts.chords', ['pageScript' => 'resources/js/pages/song.js'])

@section('title', 'ThunderChords')

@section('body')
@auth
<x-chords-shell nav="songs">
  <div class="page-heading noprint">
    <a href="/" class="btn noprint" id="home">Library</a>
    <div class="ttl">
      <h1 id="title"></h1>
      <div id="artist" class="subheading"></div>
    </div>
    <div class="row noprint" style="margin:0">
      <button id="print" class="btn">PDF</button>
      <button id="addset" class="btn" data-mgmt data-needs-net hidden>Add to setlist</button>
      <button id="share" class="btn" data-mgmt data-needs-net hidden>Share</button>
      <button id="reimport" class="btn" data-mgmt data-needs-net hidden>Re-import</button>
      <button id="edit" class="btn" data-mgmt data-needs-net hidden>Edit</button>
    </div>
  </div>

  <div class="sticky noprint page-card" style="padding:0;overflow:hidden">
    <div id="plnav" class="plnav" hidden><button id="plprev" class="btn">Previous</button><b id="plpos"></b><button id="plnext" class="btn">Next</button></div>
    <nav class="tools" id="tools"></nav>
    <div id="fpanel" class="panel" hidden></div>
  </div>

  <section class="page-card" style="margin-top:16px">
    <div id="meta" class="muted text-sm"></div>
    <div id="sheet"></div>
    <form id="editor" hidden>
      <div class="row"><input id="f-title" placeholder="Title" required><input id="f-artist" placeholder="Artist"></div>
      <div class="row"><input id="f-key" placeholder="Key (e.g. Am)"><input id="f-capo" type="number" min="0" max="12" placeholder="Capo"><input id="f-tags" placeholder="Tags, comma separated"></div>
      <textarea id="f-body" spellcheck="false"></textarea>
      <div class="row"><button class="primary">Save changes</button><button type="button" id="cancel" class="btn">Cancel</button><button type="button" id="del" class="danger btn">Delete song</button></div>
    </form>
  </section>
</x-chords-shell>
@else
<div class="share-shell">
  <header class="share-bar noprint">
    <a href="/" class="btn" id="home">Library</a>
    <div class="ttl"><h1 id="title"></h1><div id="artist" class="muted text-sm"></div></div>
    <button id="print" class="btn">PDF</button>
    <button id="addset" class="btn" data-mgmt data-needs-net hidden>Add to setlist</button>
    <button id="share" class="btn" data-mgmt data-needs-net hidden>Share</button>
    <button id="reimport" class="btn" data-mgmt data-needs-net hidden>Re-import</button>
    <button id="edit" class="btn" data-mgmt data-needs-net hidden>Edit</button>
  </header>
  <div class="sticky noprint">
    <div id="plnav" class="plnav" hidden><button id="plprev" class="btn">Previous</button><b id="plpos"></b><button id="plnext" class="btn">Next</button></div>
    <nav class="tools" id="tools"></nav>
    <div id="fpanel" class="panel" hidden></div>
  </div>
  <main>
    <div id="meta" class="muted text-sm"></div>
    <div id="sheet"></div>
    <form id="editor" hidden>
      <div class="row"><input id="f-title" placeholder="Title" required><input id="f-artist" placeholder="Artist"></div>
      <div class="row"><input id="f-key" placeholder="Key (e.g. Am)"><input id="f-capo" type="number" min="0" max="12" placeholder="Capo"><input id="f-tags" placeholder="Tags, comma separated"></div>
      <textarea id="f-body" spellcheck="false"></textarea>
      <div class="row"><button class="primary">Save changes</button><button type="button" id="cancel" class="btn">Cancel</button><button type="button" id="del" class="danger btn">Delete song</button></div>
    </form>
  </main>
</div>
@endauth

<dialog id="rdlg" class="wide">
  <h2>Re-import from the source</h2>
  <p class="muted" id="rmsg">Fetching...</p>
  <p id="rmeta" class="muted" hidden></p>
  <label id="rmetaupd" class="field" hidden><input id="rupdmeta" type="checkbox"> Update key and capo from source</label>
  <div class="cmp" id="rcmp" hidden><div><h3>Current</h3><div id="rold" class="sheet"></div></div><div><h3>New</h3><div id="rnew" class="sheet"></div></div></div>
  <p class="muted" id="rnote" hidden>Only the sheet text is replaced unless you tick the box above. Title and tags stay. Previous versions are kept.</p>
  <div class="row"><button id="rok" class="primary" hidden>Replace with new version</button><button id="rclose" class="btn">Close</button></div>
</dialog>

<dialog id="ldlg">
  <h2>Add to setlist</h2>
  <section><select id="lsel"></select><input id="lnew" placeholder="Or a new setlist name"></section>
  <p class="err" id="lerr"></p>
  <div class="row"><button id="lok" class="primary">Add</button><button id="lclose" class="btn">Close</button></div>
</dialog>
@endsection
