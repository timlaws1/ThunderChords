<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LibraryService;
use App\Services\MedleySuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedleyController extends Controller
{
    public function __construct(
        private LibraryService $lib,
        private MedleySuggester $suggester,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = $request->user()->medleys()->with('items')->get();

        return response()->json($list->map->toBriefArray()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $b = $request->all();
        $name = $this->lib->one($b['name'] ?? '');
        if ($name === '') {
            return response()->json(['error' => 'Give it a name.'], 400);
        }
        $medley = $user->medleys()->create([
            'slug' => $this->lib->collectionSlugFor($user, 'medleys', $name),
            'name' => $name,
            'target_key' => array_key_exists('targetKey', $b)
                ? substr($this->lib->one($b['targetKey']), 0, 12)
                : '',
        ]);
        $this->lib->syncMedleyItems($medley, $this->lib->cleanItems($b['items'] ?? [], true, $user));
        $medley->load(['items.song']);

        return response()->json($medley->toApiArray());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $medley = $request->user()->medleys()->where('slug', $id)->with(['items.song'])->first();
        if (! $medley) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($medley->toApiArray());
    }

    public function full(Request $request, string $id): JsonResponse
    {
        $b = $this->lib->bundle($request->user(), 'medley', $id);
        if (! $b) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($b);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $medley = $user->medleys()->where('slug', $id)->first();
        if (! $medley) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $b = $request->all();
        $medley->name = $this->lib->one($b['name'] ?? '') ?: $medley->name;
        if (array_key_exists('targetKey', $b)) {
            $medley->target_key = substr($this->lib->one($b['targetKey']), 0, 12);
        }
        $medley->save();
        if (array_key_exists('items', $b)) {
            $this->lib->syncMedleyItems($medley, $this->lib->cleanItems($b['items'] ?? [], true, $user));
        }
        $medley->load(['items.song']);

        return response()->json($medley->toApiArray());
    }

    public function suggestions(Request $request): JsonResponse
    {
        $songs = $request->input('songs', []);
        if (! is_array($songs) || $songs === []) {
            return response()->json(['error' => 'Add a song first.'], 400);
        }

        $slugs = [];
        foreach (array_slice($songs, 0, 200) as $slug) {
            if (is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                $slugs[] = $slug;
            }
        }
        if ($slugs === [] || ! $request->user()->songs()->whereIn('slug', $slugs)->exists()) {
            return response()->json(['error' => 'Add a song first.'], 400);
        }

        return response()->json([
            'suggestions' => $this->suggester->suggest(
                $request->user(),
                $slugs,
                $this->lib->one($request->input('targetKey', '')),
            ),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $medley = $request->user()->medleys()->where('slug', $id)->first();
        if (! $medley) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $medley->delete();

        return response()->json(['ok' => true]);
    }
}
