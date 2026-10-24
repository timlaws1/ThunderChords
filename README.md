# ThunderChords

Personal chord library built with Laravel, Breeze (Blade), Tailwind, and SQLite. Paste a chord-sheet link, transpose, autoscroll, build setlists and medleys, and share read-only links. Invite-only accounts each get their own library.

The previous Node/Hono app is preserved under [`old_site/`](old_site/) for reference.

## Requirements

- PHP 8.2+ (with SQLite, cURL, and Sodium)
- Composer
- Node.js 20+

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve
```

Open http://127.0.0.1:8000

### Environment

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel encryption key (`php artisan key:generate`) |
| `DB_CONNECTION=sqlite` | Uses `database/database.sqlite` |
| `INVITE_CODE` | When set, `/register` accepts new accounts with this code. Empty closes signup. |
| `ALLOW_LOCAL_IMPORT` | Set `true` only in tests to allow importing from localhost/private URLs |
| `SESSION_LIFETIME` | Defaults to a long-lived session (minutes) |
| `LASTFM_API_KEY` | Optional. Last.fm similar tracks ("often played with") when building a medley |
| `MUSICBRAINZ_USER_AGENT` | Contact User-Agent for MusicBrainz genre lookups. Defaults to the app name and `APP_URL` |

Create the first account via `/register` when `INVITE_CODE` is set, or `php artisan tinker` / a seeder.

### Import from the old Node app

```bash
php artisan chords:import-legacy
# or: php artisan chords:import-legacy old_site/data --force
```

Legacy scrypt password hashes are stored and upgraded to bcrypt on the next successful login.

## Development

```bash
npm run dev          # Vite + Tailwind
php artisan serve
php artisan test     # PHPUnit
npm run test:js      # Chord/music unit tests (Node)
```

## Features

- Link import (Ultimate Guitar + generic `<pre>` fallback) or paste
- Transpose, capo, sharps/flats, easier-chords suggestions
- Autoscroll, text size, two-column paging, PDF via print
- Setlists with per-item transpose/capo; medleys with sections and key matching
- Genre tags from MusicBrainz and the iTunes catalogue (no key). Optional Last.fm key for similar tracks
- Medley builder suggests library songs after you add one ("How about adding these tracks?")
- Share links (7d / 30d / never) without login
- Offline: install as PWA, “Save for offline” on the library page
- Dark / light theme

## Deploy

Serve `public/` with PHP-FPM or `php artisan serve`. Run `composer install --no-dev`, `php artisan migrate --force`, and `npm ci && npm run build`. Point Nginx at `public/` (no Node proxy). Back up `database/database.sqlite`.
