<?php

namespace App\Services;

use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Collection;

class MedleySuggester
{
    public function __construct(
        private MusicMetadata $metadata,
        private KeyGuesser $keys,
    ) {}

    /**
     * Library songs that fit a medley. The last slug is the song just added.
     *
     * @param  list<string>  $slugs
     * @return list<array{id: string, title: string, artist: string, key: string, reason: string}>
     */
    public function suggest(User $user, array $slugs, string $targetKey = '', int $limit = 6): array
    {
        /** @var Collection<string, Song> $bySlug */
        $bySlug = $user->songs()->get()->keyBy('slug');
        /** @var Collection<int, Song> $medley */
        $medley = collect($slugs)
            ->map(fn (string $slug): ?Song => $bySlug->get($slug))
            ->filter()
            ->values();
        if ($medley->isEmpty()) {
            return [];
        }

        /** @var Song $anchor */
        $anchor = $medley->last();
        $medleyIds = $medley->pluck('id')->all();
        $anchorKey = $this->effectiveKey($anchor);
        $similar = $this->similarIndex($anchor);

        $ranked = [];
        foreach ($bySlug as $song) {
            if (in_array($song->id, $medleyIds, true)) {
                continue;
            }
            $scored = $this->score(
                $song,
                $medley,
                $anchor,
                $anchorKey,
                $targetKey,
                isset($similar[$this->metadata->trackKey($song->artist, $song->title)]),
            );
            if ($scored !== null) {
                $ranked[] = $scored;
            }
        }

        usort($ranked, function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: strcmp($left['title'], $right['title'])
                ?: strcmp($left['id'], $right['id']);
        });

        return array_map(function (array $row): array {
            unset($row['score']);

            return $row;
        }, array_slice($ranked, 0, max(1, $limit)));
    }

    /**
     * @param  Collection<int, Song>  $medley
     * @return array{id: string, title: string, artist: string, key: string, reason: string, score: int}|null
     */
    private function score(Song $song, Collection $medley, Song $anchor, string $anchorKey, string $targetKey, bool $similar): ?array
    {
        $tags = $this->tagsOf($song);
        $genreSong = null;
        $overlap = [];
        $sameArtist = false;

        foreach ($medley as $member) {
            $shared = array_values(array_intersect($tags, $this->tagsOf($member)));
            if (count($shared) > count($overlap) || ($shared !== [] && count($shared) === count($overlap) && $member->is($anchor))) {
                $genreSong = $member;
                $overlap = $shared;
            }
            if ($this->sameArtist($song, $member)) {
                $sameArtist = true;
            }
        }

        $distance = $this->keyDistance($this->effectiveKey($song), $anchorKey, $targetKey);
        $keyScore = match ($distance) {
            0 => 3,
            1 => 2,
            2 => 1,
            default => 0,
        };
        $score = (count($overlap) * 4) + ($sameArtist ? 5 : 0) + ($similar ? 8 : 0) + $keyScore;
        if ($score < 4 || ($overlap === [] && ! $sameArtist && ! $similar)) {
            return null;
        }

        return [
            'id' => $song->slug,
            'title' => $song->title,
            'artist' => $song->artist,
            'key' => (string) $song->key,
            'reason' => $this->reason($song, $anchor, $genreSong, $overlap, $sameArtist, $similar, $distance),
            'score' => $score,
        ];
    }

    /**
     * @param  list<string>  $overlap
     */
    private function reason(Song $song, Song $anchor, ?Song $genreSong, array $overlap, bool $sameArtist, bool $similar, ?int $distance): string
    {
        $parts = [];
        if ($similar) {
            $parts[] = 'Often played with '.$anchor->title;
        }
        if ($overlap !== [] && $genreSong !== null) {
            $parts[] = 'Same genre as '.$genreSong->title.': '.implode(', ', array_slice($overlap, 0, 2));
        }
        if ($sameArtist && $song->artist !== '') {
            $parts[] = 'Also by '.$song->artist;
        }
        if ($distance !== null && $distance <= 1 && count($parts) < 2) {
            $parts[] = 'Close in key to '.$anchor->title;
        }

        return implode(' · ', array_slice($parts, 0, 2));
    }

    /**
     * @return array<string, true>
     */
    private function similarIndex(Song $anchor): array
    {
        $index = [];
        foreach ($this->metadata->similarTracks($anchor->artist, $anchor->title) as $track) {
            $index[$this->metadata->trackKey($track['artist'], $track['title'])] = true;
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function tagsOf(Song $song): array
    {
        $tags = [];
        foreach ($song->tags ?? [] as $tag) {
            $clean = $this->metadata->normalizeName((string) $tag);
            if ($clean !== '' && ! in_array($clean, $tags, true)) {
                $tags[] = $clean;
            }
        }

        return $tags;
    }

    private function sameArtist(Song $song, Song $member): bool
    {
        if ($song->artist === '' || $member->artist === '') {
            return false;
        }

        return $this->metadata->namesMatch($song->artist, $member->artist);
    }

    private function effectiveKey(Song $song): string
    {
        $guess = $this->keys->guessKey($song->body, (string) $song->key);

        return $guess['key'] ?? '';
    }

    private function keyDistance(string $songKey, string $anchorKey, string $targetKey): ?int
    {
        $distances = [];
        if ($anchorKey !== '') {
            $fromAnchor = $this->keys->fifthsDistance($anchorKey, $songKey);
            if ($fromAnchor !== null) {
                $distances[] = $fromAnchor;
            }
        }
        if ($targetKey !== '') {
            $fromTarget = $this->keys->fifthsDistance($targetKey, $songKey);
            if ($fromTarget !== null) {
                $distances[] = $fromTarget;
            }
        }

        return $distances === [] ? null : min($distances);
    }
}
