<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SetlistController extends Controller
{
    public function __construct(private LibraryService $lib) {}

    public function index(Request $request): JsonResponse
    {
        $list = $request->user()->setlists()->with('items')->get();

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
        $setlist = $user->setlists()->create([
            'slug' => $this->lib->collectionSlugFor($user, 'setlists', $name),
            'name' => $name,
            'created' => Carbon::now()->toDateString(),
        ]);
        $items = $this->lib->cleanItems($b['items'] ?? [], false, $user);
        $this->lib->syncSetlistItems($setlist, $items);
        $setlist->load(['items.song']);

        return response()->json($setlist->toApiArray());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $setlist = $request->user()->setlists()->where('slug', $id)->with(['items.song'])->first();
        if (! $setlist) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($setlist->toApiArray());
    }

    public function full(Request $request, string $id): JsonResponse
    {
        $b = $this->lib->bundle($request->user(), 'setlist', $id);
        if (! $b) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($b);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $setlist = $user->setlists()->where('slug', $id)->first();
        if (! $setlist) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $b = $request->all();
        $setlist->name = $this->lib->one($b['name'] ?? '') ?: $setlist->name;
        $setlist->save();
        if (array_key_exists('items', $b)) {
            $this->lib->syncSetlistItems($setlist, $this->lib->cleanItems($b['items'] ?? [], false, $user));
        }
        $setlist->load(['items.song']);

        return response()->json($setlist->toApiArray());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $setlist = $request->user()->setlists()->where('slug', $id)->first();
        if (! $setlist) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $setlist->delete();

        return response()->json(['ok' => true]);
    }
}
