<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Genre tags and similar tracks from public music catalogues.
 *
 * MusicBrainz and the iTunes Search API need no key. Last.fm is used as well
 * when LASTFM_API_KEY is set, including similar tracks for medley ideas.
 */
class MusicMetadata
{
    private const TAG_LIMIT = 8;

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 12;

    /**
     * Folksonomy labels that are not useful as song categories.
     *
     * @var list<string>
     */
    private const SKIPPED_TAGS = [
        'seen live',
        'favorites',
        'favourites',
        'favorite',
        'favourite',
        'my favorites',
        'my favourites',
        'albums i own',
        'awesome',
        'beautiful',
        'love',
        'cool',
        'sexy',
        'catchy',
        'amazing',
        'great',
        'good',
        'best',
        'wishlist',
        'spotify',
        'youtube',
        'under 2000 listeners',
        'male vocalists',
        'female vocalists',
        'music',
    ];

    /**
     * @return list<string>
     */
    public function tagsFor(string $artist, string $title): array
    {
        $artist = trim($artist);
        $title = trim($title);
        if ($artist === '' || $title === '') {
            return [];
        }

        return $this->remember(
            'music-tags:v1:'.hash('sha256', Str::lower($artist).'|'.Str::lower($title)),
            fn (): array => $this->fetchTags($artist, $title),
        );
    }

    /**
     * Tracks often played with this one. Empty unless a Last.fm key is configured.
     *
     * @return list<array{title: string, artist: string}>
     */
    public function similarTracks(string $artist, string $title): array
    {
        $artist = trim($artist);
        $title = trim($title);
        $key = trim((string) config('services.lastfm.key'));
        if ($artist === '' || $title === '' || $key === '') {
            return [];
        }

        return $this->remember(
            'music-similar:v1:'.hash('sha256', Str::lower($artist).'|'.Str::lower($title)),
            fn (): array => $this->fetchSimilar($artist, $title, $key),
        );
    }

    public function namesMatch(string $left, string $right): bool
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        return $this->withoutArticle($left) === $this->withoutArticle($right);
    }

    public function trackKey(string $artist, string $title): string
    {
        return $this->normalizeTitle($title).'|'.$this->withoutArticle($this->normalizeName($artist));
    }

    public function normalizeName(string $value): string
    {
        $value = Str::lower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * A finished catalogue answer is kept for two weeks. A timeout or error is kept briefly
     * so the next save can try again.
     *
     * @param  callable(): array{0: array<int, mixed>, 1: bool}  $resolve
     * @return array<int, mixed>
     */
    private function remember(string $key, callable $resolve): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        [$value, $complete] = $resolve();
        Cache::put($key, $value, $complete && $value !== [] ? now()->addDays(14) : now()->addMinutes(10));

        return $value;
    }

    /**
     * @return array{0: list<string>, 1: bool}
     */
    private function fetchTags(string $artist, string $title): array
    {
        $lastfmKey = trim((string) config('services.lastfm.key'));

        try {
            $responses = Http::pool(function (Pool $pool) use ($artist, $title, $lastfmKey): array {
                $pool->as('itunes')
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(self::TIMEOUT)
                    ->acceptJson()
                    ->get($this->url((string) config('services.itunes.base_url'), '/search'), [
                        'term' => trim($artist.' '.$title),
                        'entity' => 'song',
                        'limit' => 5,
                    ]);

                $pool->as('musicbrainz')
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(self::TIMEOUT)
                    ->acceptJson()
                    ->withUserAgent($this->userAgent())
                    ->get($this->url((string) config('services.musicbrainz.base_url'), '/artist'), [
                        'query' => 'artist:"'.$this->lucenePhrase($artist).'"',
                        'fmt' => 'json',
                        'limit' => 5,
                    ]);

                if ($lastfmKey !== '') {
                    $pool->as('lastfm')
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout(self::TIMEOUT)
                        ->acceptJson()
                        ->get($this->url((string) config('services.lastfm.base_url'), '/'), [
                            'method' => 'track.getTopTags',
                            'artist' => $artist,
                            'track' => $title,
                            'api_key' => $lastfmKey,
                            'format' => 'json',
                            'autocorrect' => 1,
                        ]);
                }

                return [];
            });
        } catch (Throwable) {
            Log::warning('Music tag lookup failed.');

            return [[], false];
        }

        if (($responses['itunes'] ?? null) instanceof Throwable || ($responses['musicbrainz'] ?? null) instanceof Throwable) {
            Log::warning('Music tag lookup failed.');
        }

        $complete = $this->settled($responses['itunes'] ?? null)
            && $this->settled($responses['musicbrainz'] ?? null)
            && ($lastfmKey === '' || $this->settled($responses['lastfm'] ?? null));

        $found = [];
        if ($lastfmKey !== '') {
            $found = array_merge($found, $this->lastfmTags($this->jsonOf($responses['lastfm'] ?? null)));
        }
        $found = array_merge($found, $this->itunesGenres($this->jsonOf($responses['itunes'] ?? null), $artist, $title));

        $mbid = $this->artistMbid($this->jsonOf($responses['musicbrainz'] ?? null), $artist);
        if ($mbid !== null) {
            [$artistJson, $lookupOk] = $this->lookupArtist($mbid);
            $complete = $complete && $lookupOk;
            $found = array_merge(
                $found,
                $this->rankedNames($artistJson, 'genres', 1, 4),
                $this->rankedNames($artistJson, 'tags', 2, 4),
            );
        }

        return [$this->finalize($found, $artist), $complete];
    }

    /**
     * @return array{0: list<array{title: string, artist: string}>, 1: bool}
     */
    private function fetchSimilar(string $artist, string $title, string $key): array
    {
        try {
            $response = $this->client()->get($this->url((string) config('services.lastfm.base_url'), '/'), [
                'method' => 'track.getSimilar',
                'artist' => $artist,
                'track' => $title,
                'api_key' => $key,
                'format' => 'json',
                'autocorrect' => 1,
                'limit' => 8,
            ]);
        } catch (ConnectionException) {
            Log::warning('Similar track lookup failed.');

            return [[], false];
        }

        $json = $this->jsonOf($response);
        if ($json === null || isset($json['error'])) {
            return [[], $response->successful() && ! isset($json['error'])];
        }

        $tracks = [];
        foreach ($this->listOf($json['similartracks']['track'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $trackTitle = trim((string) ($row['name'] ?? ''));
            $trackArtist = trim((string) (is_array($row['artist'] ?? null) ? ($row['artist']['name'] ?? '') : ''));
            if ($trackTitle === '' || $trackArtist === '') {
                continue;
            }
            $tracks[] = ['title' => $trackTitle, 'artist' => $trackArtist];
            if (count($tracks) >= 8) {
                break;
            }
        }

        return [$tracks, $response->successful()];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function lookupArtist(string $mbid): array
    {
        try {
            $response = $this->client()
                ->withUserAgent($this->userAgent())
                ->get($this->url((string) config('services.musicbrainz.base_url'), '/artist/'.$mbid), [
                    'inc' => 'genres+tags',
                    'fmt' => 'json',
                ]);
        } catch (ConnectionException) {
            Log::warning('Music tag lookup failed.');

            return [null, false];
        }

        return [$this->jsonOf($response), $response->successful()];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<string>
     */
    private function itunesGenres(?array $json, string $artist, string $title): array
    {
        if ($json === null) {
            return [];
        }
        $results = $json['results'] ?? null;
        if (! is_array($results)) {
            return [];
        }

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $gotTitle = $this->normalizeTitle((string) ($row['trackName'] ?? ''));
            $gotArtist = $this->normalizeName((string) ($row['artistName'] ?? ''));
            if (! $this->titlesMatch($this->normalizeTitle($title), $gotTitle)) {
                continue;
            }
            if (! $this->namesMatch($artist, $gotArtist)) {
                continue;
            }
            $genre = trim((string) ($row['primaryGenreName'] ?? ''));
            if ($genre !== '') {
                return [$genre];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<string>
     */
    private function lastfmTags(?array $json): array
    {
        if ($json === null || isset($json['error'])) {
            return [];
        }
        $tags = $json['toptags']['tag'] ?? null;

        return $this->rankedNames(['tags' => $this->listOf($tags)], 'tags', 15, 5);
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function artistMbid(?array $json, string $artist): ?string
    {
        if ($json === null || ! is_array($json['artists'] ?? null)) {
            return null;
        }

        foreach ($json['artists'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            $score = (int) ($row['score'] ?? 0);
            if (! is_string($id) || ! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                continue;
            }
            if ($score < 90 || ! $this->namesMatch($artist, (string) ($row['name'] ?? ''))) {
                continue;
            }

            return $id;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return list<string>
     */
    private function rankedNames(?array $json, string $key, int $minCount, int $limit): array
    {
        $rows = $json[$key] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $count = array_key_exists('count', $row) ? (int) $row['count'] : $minCount;
            if ($count < $minCount) {
                continue;
            }
            $items[] = ['name' => $name, 'count' => $count];
        }

        usort($items, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_map(
            fn (array $item): string => $item['name'],
            array_slice($items, 0, $limit),
        );
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function finalize(array $tags, string $artist): array
    {
        $artistName = $this->normalizeName($artist);
        $out = [];
        foreach ($tags as $tag) {
            $clean = $this->cleanTag($tag);
            if ($clean === null || $clean === $artistName || $clean === $this->withoutArticle($artistName)) {
                continue;
            }
            if (in_array($clean, $out, true)) {
                continue;
            }
            $out[] = $clean;
            if (count($out) >= self::TAG_LIMIT) {
                break;
            }
        }

        return $out;
    }

    private function cleanTag(string $tag): ?string
    {
        $tag = Str::of($tag)->lower()->squish()->trim()->toString();
        $tag = trim($tag, '.-_');
        if ($tag === '' || Str::length($tag) < 2 || Str::length($tag) > 40) {
            return null;
        }
        if (preg_match('/^\d+$/', $tag) === 1) {
            return null;
        }
        if (in_array($tag, self::SKIPPED_TAGS, true)) {
            return null;
        }

        return $tag;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        if (array_is_list($value)) {
            return $value;
        }
        if (isset($value['name'])) {
            return [$value];
        }

        return [];
    }

    private function settled(mixed $response): bool
    {
        return $response instanceof Response && $response->successful();
    }

    private function jsonOf(mixed $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function titlesMatch(string $want, string $got): bool
    {
        return $want !== '' && $want === $got;
    }

    private function normalizeTitle(string $value): string
    {
        $value = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $value) ?? $value;

        return $this->normalizeName($value);
    }

    private function withoutArticle(string $value): string
    {
        return preg_replace('/^the /', '', $value) ?? $value;
    }

    private function lucenePhrase(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return addcslashes($value, '"\\');
    }

    private function userAgent(): string
    {
        $agent = trim((string) config('services.musicbrainz.user_agent'));
        if ($agent !== '') {
            return $agent;
        }

        return 'ThunderChords/1.0 ('.rtrim((string) config('app.url'), '/').')';
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->acceptJson();
    }

    private function url(string $base, string $path): string
    {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }
}
