<?php

namespace App\Services;

use App\Models\Song;
use Illuminate\Support\Str;

class SongTagger
{
    public function __construct(private MusicMetadata $metadata) {}

    /**
     * @return list<string>
     */
    public function suggest(string $artist, string $title): array
    {
        return $this->metadata->tagsFor($artist, $title);
    }

    /**
     * Add catalogue genres alongside tags the user already chose.
     */
    public function apply(Song $song): Song
    {
        $suggested = $this->suggest($song->artist, $song->title);
        if ($suggested === []) {
            return $song;
        }

        $merged = $this->merge($song->tags ?? [], $suggested);
        if ($merged === array_values($song->tags ?? [])) {
            return $song;
        }

        $song->tags = $merged;
        $song->save();

        return $song;
    }

    /**
     * @param  list<mixed>  $existing
     * @param  list<string>  $suggested
     * @return list<string>
     */
    private function merge(array $existing, array $suggested): array
    {
        $out = [];
        $seen = [];
        foreach ($existing as $tag) {
            $clean = trim((string) $tag);
            $key = Str::lower($clean);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }
        foreach ($suggested as $tag) {
            $key = Str::lower($tag);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }
}
