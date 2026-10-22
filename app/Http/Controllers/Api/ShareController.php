<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use App\Services\LibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

class ShareController extends Controller
{
    private const DAYS = ['7d' => 7, '30d' => 30, 'never' => 0];

    public function __construct(private LibraryService $lib) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = (string) $request->query('type', '');
        $id = (string) $request->query('id', '');
        $out = [];
        foreach (Share::query()->where('user_id', $user->id)->where('type', $type)->get() as $s) {
            $resolved = $this->resolveShare($s);
            if (! $resolved) {
                continue;
            }
            $target = $this->targetSlug($user, $s);
            if ($target !== $id) {
                continue;
            }
            $out[] = $this->sharePayload($s);
        }

        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $b = $request->all();
        $type = (string) ($b['type'] ?? '');
        $slug = (string) ($b['id'] ?? '');
        if (! $this->lib->bundle($user, $type, $slug)) {
            return response()->json(['error' => 'Nothing to share.'], 404);
        }
        $targetId = $this->resolveTargetId($user, $type, $slug);
        if (! $targetId) {
            return response()->json(['error' => 'Nothing to share.'], 404);
        }
        $days = array_key_exists($b['expires'] ?? '', self::DAYS) ? self::DAYS[$b['expires']] : 30;
        $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $share = Share::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'type' => $type,
            'target_id' => $targetId,
            'expires_at' => $days ? Carbon::now()->addDays($days) : null,
        ]);

        return response()->json($this->sharePayload($share));
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $share = Share::query()->where('token', $token)->where('user_id', $request->user()->id)->first();
        if (! $share) {
            return response()->json(['error' => 'Link not found'], 404);
        }
        $share->delete();

        return response()->json(['ok' => true]);
    }

    public function page(Request $request, string $token)
    {
        if ($this->limited($request)) {
            return response('Too many requests', 429);
        }
        if (! $this->shareFor($token)) {
            return response('This link has expired or was removed.', 404);
        }

        return response()
            ->view('chords.song')
            ->header('Cache-Control', 'no-store');
    }

    public function api(Request $request, string $token): JsonResponse
    {
        if ($this->limited($request)) {
            return response()->json(['error' => 'Too many requests'], 429)->header('Cache-Control', 'no-store');
        }
        $share = $this->shareFor($token);
        if (! $share) {
            return response()->json(['error' => 'This link has expired or was removed.'], 404)->header('Cache-Control', 'no-store');
        }
        $user = User::query()->find($share->user_id);
        if (! $user) {
            return response()->json(['error' => 'This link has expired or was removed.'], 404)->header('Cache-Control', 'no-store');
        }
        $slug = $this->targetSlug($user, $share);
        $bundle = $slug ? $this->lib->bundle($user, $share->type, $slug, true) : null;
        if (! $bundle) {
            return response()->json(['error' => 'This link has expired or was removed.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json($bundle)->header('Cache-Control', 'no-store');
    }

    private function limited(Request $request): bool
    {
        $ip = $request->ip() ?: 'local';
        $key = 'share-hit:'.$ip;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return true;
        }
        RateLimiter::hit($key, 60);

        return false;
    }

    private function shareFor(string $token): ?Share
    {
        if (! preg_match('/^[A-Za-z0-9_-]{16,40}$/', $token)) {
            return null;
        }
        $share = Share::query()->where('token', $token)->first();

        return $this->resolveShare($share);
    }

    private function resolveShare(?Share $share): ?Share
    {
        if (! $share) {
            return null;
        }
        if ($share->expires_at && $share->expires_at->isPast()) {
            $share->delete();

            return null;
        }
        if (! User::query()->whereKey($share->user_id)->exists()) {
            return null;
        }

        return $share;
    }

    private function resolveTargetId(User $user, string $type, string $slug): ?int
    {
        return match ($type) {
            'song' => $user->songs()->where('slug', $slug)->value('id'),
            'setlist' => $user->setlists()->where('slug', $slug)->value('id'),
            'medley' => $user->medleys()->where('slug', $slug)->value('id'),
            default => null,
        };
    }

    private function targetSlug(User $user, Share $share): ?string
    {
        return match ($share->type) {
            'song' => $user->songs()->whereKey($share->target_id)->value('slug'),
            'setlist' => $user->setlists()->whereKey($share->target_id)->value('slug'),
            'medley' => $user->medleys()->whereKey($share->target_id)->value('slug'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function sharePayload(Share $share): array
    {
        return [
            'token' => $share->token,
            'url' => '/s/'.$share->token,
            'userId' => (string) $share->user_id,
            'type' => $share->type,
            'id' => $this->targetSlug(User::query()->findOrFail($share->user_id), $share),
            'created' => $share->created_at?->toIso8601String(),
            'expires' => $share->expires_at?->toIso8601String(),
        ];
    }
}
