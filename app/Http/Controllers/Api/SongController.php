<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Models\SongOpen;
use App\Services\KeyGuesser;
use App\Services\LibraryService;
use App\Services\SheetParser;
use App\Services\SongImporter;
use App\Services\SongTagger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class SongController extends Controller
{
    public function __construct(
        private LibraryService $lib,
        private SongImporter $importer,
        private SheetParser $parser,
        private KeyGuesser $keys,
        private SongTagger $tagger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = strtolower(trim((string) $request->query('q', '')));
        $songs = $user->songs()->with('ownerSongOpen')->get();
        if ($q !== '') {
            $songs = $songs->filter(function (Song $s) use ($q) {
                $hay = strtolower($s->title."\n".$s->artist."\n".implode(' ', $s->tags ?? [])."\n".$s->body);

                return str_contains($hay, $q);
            })->values();
        }

        return response()->json($songs->map(fn (Song $s) => $s->toBriefArray())->values());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }

        return response()->json($song->toApiArray());
    }

    public function opened(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $song = $user->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        $open = SongOpen::query()->firstOrNew(['user_id' => $user->id, 'song_id' => $song->id]);
        $open->last_opened = Carbon::now();
        $open->count = ((int) $open->count) + 1;
        $open->save();

        return response()->json(['ok' => true]);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $b = $request->all();
        try {
            if (! empty($b['url'])) {
                $url = trim((string) $b['url']);
                $dup = $user->songs()->where('source', $url)->first();
                $d = $this->importer->importFromUrl($url);

                return response()->json([
                    'title' => $d['title'] ?? '',
                    'artist' => $d['artist'] ?? '',
                    'key' => $d['key'] ?? '',
                    'capo' => (int) ($d['capo'] ?? 0),
                    'body' => $d['body'],
                    'source' => $url,
                    'keyGuessed' => (bool) ($d['keyGuessed'] ?? false),
                    'duplicateId' => $dup?->slug,
                ]);
            }
            if (! empty($b['text'])) {
                $parsed = $this->parser->parseSheet((string) $b['text']);
                $d = $this->keys->withGuessedKey([
                    'title' => $b['title'] ?? '',
                    'artist' => $b['artist'] ?? '',
                    'key' => $parsed['key'],
                    'capo' => $parsed['capo'],
                    'body' => $parsed['body'],
                ]);

                return response()->json([
                    'title' => $d['title'] ?? '',
                    'artist' => $d['artist'] ?? '',
                    'key' => $d['key'] ?? '',
                    'capo' => (int) ($d['capo'] ?? 0),
                    'body' => $d['body'],
                    'source' => '',
                    'keyGuessed' => (bool) ($d['keyGuessed'] ?? false),
                    'duplicateId' => null,
                ]);
            }

            return response()->json(['error' => 'Give a link or paste some text.'], 400);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        $b = $request->all();
        try {
            if (is_string($b['body'] ?? null) && empty($b['url'])) {
                $source = $this->lib->one($b['source'] ?? '');
                if ($source !== '') {
                    $dup = $user->songs()->where('source', $source)->first();
                    if ($dup) {
                        return response()->json(['id' => $dup->slug, 'duplicate' => true]);
                    }
                }
                $body = str_replace("\r\n", "\n", $b['body']);
                if (! str_ends_with($body, "\n")) {
                    $body .= "\n";
                }
                $d = [
                    'title' => $b['title'] ?? '',
                    'artist' => $b['artist'] ?? '',
                    'key' => $b['key'] ?? '',
                    'capo' => $b['capo'] ?? 0,
                    'source' => $source,
                    'body' => $body,
                ];
            } elseif (! empty($b['url'])) {
                $url = trim((string) $b['url']);
                $dup = $user->songs()->where('source', $url)->first();
                if ($dup) {
                    return response()->json(['id' => $dup->slug, 'duplicate' => true]);
                }
                $d = $this->importer->importFromUrl($url);
                $d['source'] = $url;
            } elseif (! empty($b['text'])) {
                $parsed = $this->parser->parseSheet((string) $b['text']);
                $d = $this->keys->withGuessedKey([
                    'title' => $b['title'] ?? '',
                    'artist' => $b['artist'] ?? '',
                    'key' => $parsed['key'],
                    'capo' => $parsed['capo'],
                    'source' => '',
                    'body' => $parsed['body'],
                ]);
            } else {
                return response()->json(['error' => 'Give a link or paste some text.'], 400);
            }

            if ($this->lib->one($d['title'] ?? '') === '') {
                throw new RuntimeException('No title found. Add a title and try again.');
            }

            $tags = is_array($b['tags'] ?? null) ? $b['tags'] : explode(',', (string) ($b['tags'] ?? ''));
            $tags = array_values(array_filter(array_map(fn ($t) => $this->lib->one($t), $tags)));
            if ($tags === [] && $request->boolean('autotag')) {
                $tags = $this->tagger->suggest(
                    $this->lib->one($d['artist'] ?? ''),
                    $this->lib->one($d['title']),
                );
            }

            $song = $user->songs()->create([
                'slug' => $this->lib->songSlugFor($user, $this->lib->one($d['artist'] ?? ''), $this->lib->one($d['title'])),
                'title' => $this->lib->one($d['title']),
                'artist' => $this->lib->one($d['artist'] ?? ''),
                'key' => $this->lib->one($d['key'] ?? ''),
                'capo' => (int) ($d['capo'] ?? 0),
                'tags' => $tags,
                'source' => $d['source'] ?? '',
                'added' => Carbon::now()->toDateString(),
                'body' => $d['body'],
            ]);

            return response()->json(['id' => $song->slug]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function reimport(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        if (! $song->source) {
            return response()->json(['error' => 'This song has no source link.'], 422);
        }
        try {
            $d = $this->importer->importFromUrl($song->source);

            return response()->json([
                'body' => $d['body'],
                'key' => $d['key'] ?? '',
                'capo' => (int) ($d['capo'] ?? 0),
                'current' => $song->body,
                'currentKey' => $song->key ?: '',
                'currentCapo' => $song->capo ?: 0,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        $b = $request->all();
        $body = rtrim(str_replace("\r\n", "\n", (string) ($b['body'] ?? '')));
        if (trim($body) === '') {
            return response()->json(['error' => 'The new version is empty.'], 400);
        }
        $this->lib->snapshot($song);
        $song->body = $body."\n";
        if (! empty($b['updateMeta'])) {
            $song->key = $this->lib->one($b['key'] ?? '');
            $song->capo = (int) ($b['capo'] ?? 0);
        }
        $song->save();

        return response()->json($song->toApiArray());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        $b = $request->all();
        $tags = is_array($b['tags'] ?? null) ? $b['tags'] : explode(',', (string) ($b['tags'] ?? ''));
        $song->title = $this->lib->one($b['title'] ?? '') ?: $song->title;
        $song->artist = $this->lib->one($b['artist'] ?? '');
        $song->key = $this->lib->one($b['key'] ?? '');
        $song->capo = (int) ($b['capo'] ?? 0);
        $song->tags = array_values(array_filter(array_map(fn ($t) => $this->lib->one($t), $tags)));
        $song->body = str_replace("\r\n", "\n", (string) ($b['body'] ?? $song->body));
        $song->save();

        return response()->json($song->toApiArray());
    }

    public function suggestTags(Request $request): JsonResponse
    {
        $title = $this->lib->one($request->input('title'));
        if ($title === '') {
            return response()->json(['error' => 'Add a title first.'], 400);
        }

        return response()->json([
            'tags' => $this->tagger->suggest($this->lib->one($request->input('artist')), $title),
        ]);
    }

    public function autotag(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        $before = array_map(fn (mixed $tag): string => Str::lower(trim((string) $tag)), $song->tags ?? []);
        $song = $this->tagger->apply($song);
        $added = array_values(array_filter(
            $song->tags ?? [],
            fn (string $tag): bool => ! in_array(Str::lower($tag), $before, true),
        ));

        return response()->json([
            ...$song->toApiArray(),
            'tagsAdded' => $added,
        ]);
    }

    public function autotagMissing(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = $user->songs()->untagged()->orderBy('id')->limit(5)->get();
        $tagged = 0;
        foreach ($pending as $song) {
            $before = $song->tags ?? [];
            $this->tagger->apply($song);
            if (($song->tags ?? []) !== $before) {
                $tagged++;
            }
        }

        return response()->json([
            'tagged' => $tagged,
            'remaining' => $user->songs()->untagged()->count(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $song = $request->user()->songs()->where('slug', $id)->first();
        if (! $song) {
            return response()->json(['error' => 'Song not found'], 404);
        }
        $song->delete();

        return response()->json(['ok' => true]);
    }
}
