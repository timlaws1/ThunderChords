<?php

namespace App\Console\Commands;

use App\Models\Medley;
use App\Models\MedleyItem;
use App\Models\Preference;
use App\Models\Setlist;
use App\Models\SetlistItem;
use App\Models\Share;
use App\Models\Song;
use App\Models\SongOpen;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportLegacyCommand extends Command
{
    protected $signature = 'chords:import-legacy
                            {path? : Path to old_site/data (default: old_site/data)}
                            {--force : Replace existing songs/setlists/medleys for imported users}';

    protected $description = 'Import users and libraries from the Node old_site/data folder into SQLite';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('old_site/data');
        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");

            return self::FAILURE;
        }

        $usersFile = $path.DIRECTORY_SEPARATOR.'users.json';
        if (! is_file($usersFile)) {
            $this->error("Missing users.json in {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($usersFile), true);
        $users = $payload['users'] ?? [];
        if (! is_array($users) || $users === []) {
            $this->warn('No users found in users.json');

            return self::SUCCESS;
        }

        $sharesFile = $path.DIRECTORY_SEPARATOR.'shares.json';
        $shares = is_file($sharesFile)
            ? (json_decode((string) file_get_contents($sharesFile), true) ?: [])
            : [];

        $idMap = [];
        foreach ($users as $legacy) {
            $email = strtolower(trim((string) ($legacy['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                $user = User::query()->create([
                    'name' => (string) ($legacy['name'] ?? explode('@', $email)[0]),
                    'email' => $email,
                    'password' => Hash::make(Str::password(32)),
                    'legacy_password' => (string) ($legacy['passwordHash'] ?? ''),
                ]);
                $this->info("Created user {$email}");
            } else {
                if (! empty($legacy['passwordHash']) && empty($user->legacy_password)) {
                    $user->forceFill(['legacy_password' => $legacy['passwordHash']])->save();
                }
                $this->line("Using existing user {$email}");
            }
            $idMap[(string) $legacy['id']] = $user;

            $libRoot = $path.DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR.$legacy['id'];
            if (! is_dir($libRoot)) {
                // Legacy single-library layout under data/
                $libRoot = $path;
            }
            $this->importLibrary($user, $libRoot);
        }

        $this->importShares($shares, $idMap);
        $this->info('Legacy import complete.');

        return self::SUCCESS;
    }

    private function importLibrary(User $user, string $root): void
    {
        $songsDir = $root.DIRECTORY_SEPARATOR.'songs';
        if (is_dir($songsDir)) {
            foreach (glob($songsDir.DIRECTORY_SEPARATOR.'*.txt') ?: [] as $file) {
                $slug = basename($file, '.txt');
                $parsed = $this->parseSongFile($slug, (string) file_get_contents($file));
                $existing = $user->songs()->where('slug', $slug)->first();
                if ($existing && ! $this->option('force')) {
                    continue;
                }
                if ($existing && $this->option('force')) {
                    $existing->update($parsed);
                } else {
                    $user->songs()->create(array_merge($parsed, ['slug' => $slug]));
                }
            }
            $this->line("  songs from {$songsDir}");
        }

        $stateFile = $root.DIRECTORY_SEPARATOR.'state.json';
        if (is_file($stateFile)) {
            $state = json_decode((string) file_get_contents($stateFile), true) ?: [];
            $this->importState($user, $state);
        }

        $this->importCollection($user, $root.DIRECTORY_SEPARATOR.'setlists', 'setlist');
        $this->importCollection($user, $root.DIRECTORY_SEPARATOR.'medleys', 'medley');
    }

    /**
     * @return array{title: string, artist: string, key: string, capo: int, tags: list<string>, source: string, added: string, body: string}
     */
    private function parseSongFile(string $id, string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $meta = [];
        $body = $text;
        if (preg_match('/^---\n([\s\S]*?)\n---\n?([\s\S]*)$/', $text, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                $i = strpos($line, ':');
                if ($i > 0) {
                    $meta[trim(substr($line, 0, $i))] = trim(substr($line, $i + 1));
                }
            }
            $body = $m[2];
        }
        $body = rtrim($body)."\n";
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($meta['tags'] ?? '')))));

        return [
            'title' => $meta['title'] ?? $id,
            'artist' => $meta['artist'] ?? '',
            'key' => $meta['key'] ?? '',
            'capo' => (int) ($meta['capo'] ?? 0),
            'tags' => $tags,
            'source' => $meta['source'] ?? '',
            'added' => $meta['added'] ?? Carbon::now()->toDateString(),
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function importState(User $user, array $state): void
    {
        $prefs = is_array($state['prefs'] ?? null) ? $state['prefs'] : [];
        foreach ($prefs as $key => $p) {
            if (! is_array($p) || ! is_string($key)) {
                continue;
            }
            Preference::query()->updateOrCreate(
                ['user_id' => $user->id, 'key' => $key],
                [
                    't' => $p['t'] ?? null,
                    'c' => $p['c'] ?? null,
                    'flats' => $p['flats'] ?? null,
                    'speed' => $p['speed'] ?? null,
                    'size' => $p['size'] ?? null,
                ]
            );
        }
        $global = is_array($state['global'] ?? null) ? $state['global'] : [];
        if (isset($global['size'])) {
            Preference::query()->updateOrCreate(
                ['user_id' => $user->id, 'key' => '_global'],
                ['size' => (int) $global['size']]
            );
        }
        $opened = is_array($state['opened'] ?? null) ? $state['opened'] : [];
        foreach ($opened as $slug => $o) {
            $song = $user->songs()->where('slug', $slug)->first();
            if (! $song || ! is_array($o)) {
                continue;
            }
            SongOpen::query()->updateOrCreate(
                ['user_id' => $user->id, 'song_id' => $song->id],
                [
                    'last_opened' => ! empty($o['last']) ? Carbon::parse($o['last']) : Carbon::now(),
                    'count' => (int) ($o['count'] ?? 0),
                ]
            );
        }
    }

    private function importCollection(User $user, string $dir, string $type): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data) || empty($data['id']) || empty($data['name'])) {
                continue;
            }
            $slug = (string) $data['id'];
            if ($type === 'setlist') {
                $existing = $user->setlists()->where('slug', $slug)->first();
                if ($existing && ! $this->option('force')) {
                    continue;
                }
                if ($existing && $this->option('force')) {
                    $existing->items()->delete();
                    $existing->update([
                        'name' => $data['name'],
                        'created' => $data['created'] ?? Carbon::now()->toDateString(),
                    ]);
                    $setlist = $existing;
                } else {
                    $setlist = $user->setlists()->create([
                        'slug' => $slug,
                        'name' => $data['name'],
                        'created' => $data['created'] ?? Carbon::now()->toDateString(),
                    ]);
                }
                foreach (array_values($data['items'] ?? []) as $pos => $item) {
                    if (! is_array($item) || empty($item['songId'])) {
                        continue;
                    }
                    $song = $user->songs()->where('slug', $item['songId'])->first();
                    SetlistItem::query()->create([
                        'setlist_id' => $setlist->id,
                        'song_id' => $song?->id,
                        'position' => $pos,
                        't' => (int) ($item['t'] ?? 0),
                        'c' => (int) ($item['c'] ?? 0),
                    ]);
                }
            } else {
                $existing = $user->medleys()->where('slug', $slug)->first();
                if ($existing && ! $this->option('force')) {
                    continue;
                }
                if ($existing && $this->option('force')) {
                    $existing->items()->delete();
                    $existing->update([
                        'name' => $data['name'],
                        'target_key' => $data['targetKey'] ?? '',
                    ]);
                    $medley = $existing;
                } else {
                    $medley = $user->medleys()->create([
                        'slug' => $slug,
                        'name' => $data['name'],
                        'target_key' => $data['targetKey'] ?? '',
                    ]);
                }
                foreach (array_values($data['items'] ?? []) as $pos => $item) {
                    if (! is_array($item) || empty($item['songId'])) {
                        continue;
                    }
                    $song = $user->songs()->where('slug', $item['songId'])->first();
                    MedleyItem::query()->create([
                        'medley_id' => $medley->id,
                        'song_id' => $song?->id,
                        'position' => $pos,
                        't' => (int) ($item['t'] ?? 0),
                        'c' => (int) ($item['c'] ?? 0),
                        'sections' => $item['sections'] ?? null,
                        'body' => $item['body'] ?? null,
                    ]);
                }
            }
        }
        $this->line("  {$type}s from {$dir}");
    }

    /**
     * @param  array<string, mixed>  $shares
     * @param  array<string, User>  $idMap
     */
    private function importShares(array $shares, array $idMap): void
    {
        foreach ($shares as $token => $s) {
            if (! is_array($s) || ! is_string($token)) {
                continue;
            }
            $legacyUserId = (string) ($s['userId'] ?? '');
            $user = $idMap[$legacyUserId] ?? null;
            if (! $user) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            $slug = (string) ($s['id'] ?? '');
            $targetId = match ($type) {
                'song' => $user->songs()->where('slug', $slug)->value('id'),
                'setlist' => $user->setlists()->where('slug', $slug)->value('id'),
                'medley' => $user->medleys()->where('slug', $slug)->value('id'),
                default => null,
            };
            if (! $targetId) {
                continue;
            }
            Share::query()->updateOrCreate(
                ['token' => $token],
                [
                    'user_id' => $user->id,
                    'type' => $type,
                    'target_id' => $targetId,
                    'expires_at' => ! empty($s['expires']) ? Carbon::parse($s['expires']) : null,
                ]
            );
        }
    }
}
