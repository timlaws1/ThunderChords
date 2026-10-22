# ThunderChords

A personal chord library. Paste a chord-sheet link, get a clean page you can transpose, autoscroll, put in setlists, mix into medleys and share.
Invite-only accounts each get their own library. Songs are plain text files under each user’s folder. Two npm packages (Hono and its Node adapter); Tailwind builds the CSS.

## Run it

    npm install
    npm run build:css
    OWNER_EMAIL=you@example.com PASSWORD=something-long SECRET=another-long-secret INVITE_CODE=friends-only npm start
    # http://127.0.0.1:3000

Environment variables:

- `OWNER_EMAIL`, `PASSWORD` — required on first boot to create the owner account (password must be at least 8 characters).
- `SECRET` — cookie signing key (required in production; if omitted on first boot it is derived from `PASSWORD`).
- `INVITE_CODE` — optional; when set, `/signup` accepts new accounts with this code.
- `PORT` (3000), `HOST` (127.0.0.1), `SONGS_DIR` (./songs), `DATA_DIR` (folder above `SONGS_DIR` when set, otherwise ./data).
- `ALLOW_LOCAL_IMPORT=1` — only for tests; allows importing from private/localhost URLs.

Needs Node 20 or newer. `npm test` runs the tests (unit tests plus a server test that starts the app).
`npm run build:css` compiles Tailwind from `src/input.css` into `public/style.css`. `npm run watch:css` rebuilds on change.

## What is stored where

- `DATA_DIR/users.json`: accounts (email, scrypt password hash, name).
- `DATA_DIR/shares.json`: active share links (each records the owning `userId`).
- `DATA_DIR/users/<id>/songs/<song-id>.txt`: one file per song (frontmatter, then the sheet). Edit by hand if you like; changes are picked up within a second.
- `DATA_DIR/users/<id>/songs/.history/`: the last 5 versions of a song before each re-import replaced it.
- `DATA_DIR/users/<id>/state.json`: per-song settings (transpose, capo, speed, size, flats), last-opened times, default text size.
- `DATA_DIR/users/<id>/setlists/*.json`, `DATA_DIR/users/<id>/medleys/*.json`: setlists and medleys for that account.

On first boot, if `users.json` is missing, the owner is created and any legacy `SONGS_DIR` plus `DATA_DIR/state.json`, `setlists/`, and `medleys/` are moved into that user’s folder.

Back up `DATA_DIR`. With the example deployment below that is `/var/lib/chords`.

## Deploy

1. Copy the folder to `/var/www/chords` and run `npm ci --omit=dev` (the built `public/style.css` is committed; rebuild with `npm run build:css` after UI changes).
2. Create `/etc/chords.env` (and `chmod 600` it):

       OWNER_EMAIL=you@example.com
       PASSWORD=your-long-password
       SECRET=a-separate-long-random-string
       INVITE_CODE=something-you-share-with-friends
       PORT=3100
       SONGS_DIR=/var/lib/chords/songs
       DATA_DIR=/var/lib/chords

   Run `mkdir -p /var/lib/chords/songs && chown -R www-data:www-data /var/lib/chords`. After the first boot, data lives under `/var/lib/chords/users/`.
3. `cp chords.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now chords`
4. Nginx on its own subdomain, then add HTTPS with certbot:

       server {
         server_name chords.example.com;
         location / {
           proxy_pass http://127.0.0.1:3100;
           proxy_http_version 1.1;
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
         }
       }

   `X-Forwarded-For` matters: share links are rate limited per visitor, and without it every visitor counts as one. `X-Forwarded-Proto` marks the login cookie as HTTPS-only.
5. Update later with `git pull && npm ci --omit=dev && systemctl restart chords`.

## Using it

- **Accounts**: sign in with email and password. With `INVITE_CODE` set, friends can open `/signup`. Account → change password or sign out.
- **Add song**: paste a link or the chords. Ultimate Guitar links are read from the page data. Other sites use a best-effort fallback (the biggest `<pre>` block). Pasting always works. Link import refuses private/internal addresses.
- **Transpose** with + and - (or `[` and `]`). Tap the number to reset. Sharps/Flats switches spelling.
- **Capo** shows the shapes to play with a capo at that fret. The key shown is the sounding key.
- **Easier chords** suggests a transposition and capo that give open shapes, and applies the one you tap.
- **Scroll**: button or space bar. `+` and `-` change speed. Touching or wheel-scrolling pauses. The screen stays awake.
- **2 columns** (screens 700px or wider): the sheet becomes sideways pages of two columns. Tap the right or left half, swipe, or use arrow and Page keys. Autoscroll is off in this mode.
- **Re-import** (songs with a source link): fetches the page again, shows old and new side by side, and replaces only the sheet text when you confirm.
- **Settings sync**: transpose, capo, speed, size and sharps/flats are saved on the server, so every device agrees.
- **Library**: sort by artist, title, newest, recently opened or key. Filter by tag. Tick songs to make a medley.
- **Setlists** (`/setlists`): reorder by dragging the handle or with the Up/Down buttons. Transpose and capo are stored per setlist. Play mode has Previous/Next (or `,` and `.`).
- **Medleys**: tick songs in the library and choose Make medley. Pick sections of each song, set the key per song or use Match keys with a target key, and Suggest order to keep neighbouring songs close in key. Keys marked "guessed" come from the first chord. It plays as one continuous page with one autoscroll.
- **Share**: Share on a song, setlist or medley makes a read-only link that needs no login (expires in 7 or 30 days, or never). Revoke any time. Shared pages show no tags or source links and can reach nothing else.
- **Offline**: install it from the browser menu (needs HTTPS). On the library page choose Save for offline to cache every song, setlist and medley. Offline you can read, transpose, scroll and play setlists. Editing, importing, re-importing and sharing are switched off, and setting changes are sent when you are back online.
- **PDF**: the PDF button opens the print dialog; choose Save as PDF.
- Line-type quirks: start a line with `\` to force it to be lyrics. Tuning lines and tab staves are never transposed.

## If something breaks

- Imports: all site-specific code is in `importer.js`. If Ultimate Guitar changes its page, fix `ultimateGuitar()` there.
- Stale pages after an update: the service worker serves saved copies and refreshes them in the background, so reload twice. To force it, change `V` at the top of `public/sw.js`.
- Reset a friend’s password: edit `DATA_DIR/users.json` is not enough (hashes are scrypt). Sign in as them is impossible without the password — temporarily set a new hash by running a one-off Node script that calls `hashPassword` from `lib/auth.js`, or recreate the account and restore their `users/<id>/` folder from backup.
