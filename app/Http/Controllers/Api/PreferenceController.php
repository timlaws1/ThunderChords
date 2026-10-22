<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Preference;
use App\Services\LibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function __construct(private LibraryService $lib) {}

    public function show(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        $prefs = null;
        if ($key !== '_global') {
            $row = Preference::query()->where('user_id', $user->id)->where('key', $key)->first();
            $prefs = $row ? $this->rowToPrefs($row) : null;
        }
        $global = Preference::query()->where('user_id', $user->id)->where('key', '_global')->first();

        return response()->json([
            'prefs' => $prefs,
            'global' => $global ? ['size' => (int) $global->size] : (object) [],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        $b = $request->all();

        if ($key === '_global') {
            if (array_key_exists('size', $b)) {
                Preference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'key' => '_global'],
                    ['size' => $this->lib->clamp($b['size'], 10, 40, 17)]
                );
            }

            return response()->json(['ok' => true]);
        }

        if (! preg_match('/^(song|medley):[a-z0-9-]+$/', $key)) {
            return response()->json(['error' => 'Bad key'], 400);
        }

        $data = $this->cleanPrefs($b);
        $row = Preference::query()->firstOrNew(['user_id' => $user->id, 'key' => $key]);
        foreach ($data as $k => $v) {
            $row->{$k} = $v;
        }
        $row->save();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function cleanPrefs(array $b): array
    {
        $o = [];
        if (array_key_exists('t', $b)) {
            $o['t'] = $this->lib->clamp($b['t'], -11, 11);
        }
        if (array_key_exists('c', $b)) {
            $o['c'] = $this->lib->clamp($b['c'], 0, 11);
        }
        if (array_key_exists('flats', $b)) {
            $o['flats'] = (bool) $b['flats'];
        }
        if (array_key_exists('speed', $b)) {
            $o['speed'] = $this->lib->clamp($b['speed'], 1, 30, 5);
        }
        if (array_key_exists('size', $b)) {
            $o['size'] = $this->lib->clamp($b['size'], 10, 40, 17);
        }

        return $o;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToPrefs(Preference $row): array
    {
        $o = [];
        if ($row->t !== null) {
            $o['t'] = (int) $row->t;
        }
        if ($row->c !== null) {
            $o['c'] = (int) $row->c;
        }
        if ($row->flats !== null) {
            $o['flats'] = (bool) $row->flats;
        }
        if ($row->speed !== null) {
            $o['speed'] = (int) $row->speed;
        }
        if ($row->size !== null) {
            $o['size'] = (int) $row->size;
        }

        return $o;
    }
}
