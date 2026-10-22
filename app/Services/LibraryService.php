<?php

namespace App\Services;

use App\Models\Medley;
use App\Models\MedleyItem;
use App\Models\Setlist;
use App\Models\SetlistItem;
use App\Models\Song;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Support\Carbon;

class LibraryService
{
    public function one(mixed $s): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', (string) ($s ?? '')) ?? '');
    }

    public function clamp(mixed $v, int $lo, int $hi, int $default = 0): int
    {
        $n = (int) round((float) $v);

        return max($lo, min($hi, $n));
    }

    public function songSlugFor(User $user, string $artist, string $title): string
    {
        return Slug::uniqueSlugForUser($user->id, trim($artist.' '.$title), function (string $slug) use ($user) {
            return $user->songs()->where('slug', $slug)->exists();
        });
    }

    public function collectionSlugFor(User $user, string $relation, string $name): string
    {
        return Slug::uniqueSlugForUser($user->id, $name, function (string $slug) use ($user, $relation) {
            return $user->{$relation}()->where('slug', $slug)->exists();
        });
    }

    /**
     * @return array{type: string, song?: array<string, mixed>, setlist?: array<string, mixed>, medley?: array<string, mixed>, songs?: array<string, array<string, mixed>>}|null
     */
    public function bundle(User $user, string $type, string $slug, bool $public = false): ?array
    {
        if (! in_array($type, ['song', 'setlist', 'medley'], true)) {
            return null;
        }

        if ($type === 'song') {
            $song = $user->songs()->where('slug', $slug)->first();
            if (! $song) {
                return null;
            }

            return [
                'type' => 'song',
                'song' => $public ? $song->toPublicArray() : $this->pub($song),
            ];
        }

        if ($type === 'setlist') {
            $item = $user->setlists()->where('slug', $slug)->with(['items.song'])->first();
            if (! $item) {
                return null;
            }
            $map = [];
            foreach ($item->items as $it) {
                if ($it->song) {
                    $map[$it->song->slug] = $public ? $it->song->toPublicArray() : $this->pub($it->song);
                }
            }

            return ['type' => 'setlist', 'setlist' => $item->toApiArray(), 'songs' => $map];
        }

        $item = $user->medleys()->where('slug', $slug)->with(['items.song'])->first();
        if (! $item) {
            return null;
        }
        $map = [];
        foreach ($item->items as $it) {
            if ($it->song) {
                $map[$it->song->slug] = $public ? $it->song->toPublicArray() : $this->pub($it->song);
            }
        }

        return ['type' => 'medley', 'medley' => $item->toApiArray(), 'songs' => $map];
    }

    /**
     * @return array{id: string, title: string, artist: string, key: string, capo: int, body: string}
     */
    public function pub(Song $s): array
    {
        return [
            'id' => $s->slug,
            'title' => $s->title,
            'artist' => $s->artist,
            'key' => $s->key,
            'capo' => (int) $s->capo,
            'body' => $s->body,
        ];
    }

    /**
     * @param  list<mixed>  $list
     * @return list<array{songId: string, t: int, c: int, sections?: list<string>|null, body?: string}>
     */
    public function cleanItems(array $list, bool $withSections, User $user): array
    {
        $out = [];
        foreach (array_slice($list, 0, 200) as $i) {
            if (! is_array($i) || ! is_string($i['songId'] ?? null) || ! preg_match('/^[a-z0-9-]+$/', $i['songId'])) {
                continue;
            }
            $song = $user->songs()->where('slug', $i['songId'])->first();
            $row = [
                'songId' => $i['songId'],
                'song_id' => $song?->id,
                't' => $this->clamp($i['t'] ?? 0, -11, 11),
                'c' => $this->clamp($i['c'] ?? 0, 0, 11),
            ];
            if ($withSections) {
                $custom = is_string($i['body'] ?? null)
                    ? rtrim(str_replace("\r\n", "\n", $i['body']))
                    : '';
                if ($custom !== '') {
                    $row['body'] = substr($custom, 0, 100000)."\n";
                    $row['sections'] = null;
                } else {
                    $sections = is_array($i['sections'] ?? null) && count($i['sections'])
                        ? array_map(fn ($s) => substr((string) $s, 0, 80), array_slice($i['sections'], 0, 40))
                        : null;
                    $row['sections'] = $sections;
                    $row['body'] = null;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array{songId: string, song_id: int|null, t: int, c: int}>  $items
     */
    public function syncSetlistItems(Setlist $setlist, array $items): void
    {
        $setlist->items()->delete();
        foreach (array_values($items) as $pos => $item) {
            SetlistItem::create([
                'setlist_id' => $setlist->id,
                'song_id' => $item['song_id'],
                'position' => $pos,
                't' => $item['t'],
                'c' => $item['c'],
            ]);
        }
    }

    /**
     * @param  list<array{songId: string, song_id: int|null, t: int, c: int, sections?: list<string>|null, body?: string|null}>  $items
     */
    public function syncMedleyItems(Medley $medley, array $items): void
    {
        $medley->items()->delete();
        foreach (array_values($items) as $pos => $item) {
            MedleyItem::create([
                'medley_id' => $medley->id,
                'song_id' => $item['song_id'],
                'position' => $pos,
                't' => $item['t'],
                'c' => $item['c'],
                'sections' => $item['sections'] ?? null,
                'body' => $item['body'] ?? null,
            ]);
        }
    }

    public function pruneRevisions(Song $song, int $keep = 5): void
    {
        $ids = $song->revisions()->orderByDesc('id')->skip($keep)->pluck('id');
        if ($ids->isNotEmpty()) {
            $song->revisions()->whereIn('id', $ids)->delete();
        }
    }

    public function snapshot(Song $song): void
    {
        $song->revisions()->create([
            'body' => $song->body,
            'key' => $song->key,
            'capo' => $song->capo,
            'created_at' => Carbon::now(),
        ]);
        $this->pruneRevisions($song);
    }
}
