<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SongTaggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lastfm.key' => null]);
    }

    public function test_guest_suggest_tags_returns_401(): void
    {
        $this->postJson('/api/songs/tags', ['title' => 'Yellow', 'artist' => 'Coldplay'])
            ->assertUnauthorized();
    }

    public function test_suggest_tags_returns_400_without_a_title(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/tags', ['title' => '  ', 'artist' => 'Coldplay'])
            ->assertBadRequest()
            ->assertJsonPath('error', 'Add a title first.');

        Http::assertNothingSent();
    }

    public function test_suggest_tags_returns_genres_for_a_matching_song(): void
    {
        $this->fakeYellow();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/tags', ['title' => 'Yellow', 'artist' => 'Coldplay'])
            ->assertOk()
            ->assertJsonPath('tags', ['alternative', 'alternative rock']);
    }

    public function test_autotag_merges_genres_onto_the_song(): void
    {
        $this->fakeYellow();
        $user = User::factory()->create();
        $song = $this->song($user, ['tags' => ['campfire']]);

        $this->actingAs($user)
            ->postJson('/api/songs/'.$song->slug.'/autotag')
            ->assertOk()
            ->assertJsonPath('tags', ['campfire', 'alternative', 'alternative rock'])
            ->assertJsonPath('tagsAdded', ['alternative', 'alternative rock']);

        $this->assertSame(['campfire', 'alternative', 'alternative rock'], $song->fresh()->tags);
    }

    public function test_autotag_leaves_the_song_unchanged_when_nothing_matches(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'itunes.apple.com/*' => Http::response(['results' => []]),
            'musicbrainz.org/*' => Http::response(['artists' => []]),
        ]);
        $user = User::factory()->create();
        $song = $this->song($user);

        $this->actingAs($user)
            ->postJson('/api/songs/'.$song->slug.'/autotag')
            ->assertOk()
            ->assertJsonPath('tagsAdded', []);

        $this->assertSame([], $song->fresh()->tags);
    }

    public function test_autotag_returns_404_for_another_users_song(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $song = $this->song($owner, ['tags' => ['secret']]);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson('/api/songs/'.$song->slug.'/autotag')
            ->assertNotFound()
            ->assertJsonPath('error', 'Song not found');

        $this->assertSame(['secret'], $song->fresh()->tags);
        Http::assertNothingSent();
    }

    public function test_autotag_missing_tags_only_untagged_songs_and_stops_at_five(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $title = trim(substr((string) ($query['term'] ?? ''), strlen('Bulk ')));

                return Http::response([
                    'results' => [[
                        'artistName' => 'Bulk',
                        'trackName' => $title,
                        'primaryGenreName' => 'Rock',
                    ]],
                ]);
            }

            return Http::response(['artists' => []]);
        });
        $user = User::factory()->create();
        $kept = $this->song($user, ['slug' => 'kept', 'title' => 'Kept', 'artist' => 'Bulk', 'tags' => ['keep']]);
        $songs = [];
        for ($n = 1; $n <= 6; $n++) {
            $songs[] = $this->song($user, [
                'slug' => 'track-'.$n,
                'title' => 'Track '.$n,
                'artist' => 'Bulk',
                'tags' => [],
            ]);
        }

        $this->actingAs($user)
            ->postJson('/api/songs/autotag')
            ->assertOk()
            ->assertJsonPath('tagged', 5)
            ->assertJsonPath('remaining', 1);

        $this->assertSame(['keep'], $kept->fresh()->tags);
        $this->assertSame(['rock'], $songs[0]->fresh()->tags);
        $this->assertSame(['rock'], $songs[4]->fresh()->tags);
        $this->assertSame([], $songs[5]->fresh()->tags);
    }

    public function test_import_with_autotag_stores_genres(): void
    {
        $this->fakeYellow();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/import', [
                'title' => 'Yellow',
                'artist' => 'Coldplay',
                'body' => "B\nlook\n",
                'autotag' => true,
            ])
            ->assertOk();

        $this->assertSame(['alternative', 'alternative rock'], $user->songs()->first()->tags);
    }

    public function test_import_without_autotag_does_not_call_the_music_apis(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/import', [
                'title' => 'Yellow',
                'artist' => 'Coldplay',
                'body' => "B\nlook\n",
            ])
            ->assertOk();

        $this->assertSame([], $user->songs()->first()->tags);
        Http::assertNothingSent();
    }

    private function fakeYellow(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'itunes.apple.com')) {
                return Http::response([
                    'results' => [[
                        'trackName' => 'Yellow',
                        'artistName' => 'Coldplay',
                        'primaryGenreName' => 'Alternative',
                    ]],
                ]);
            }
            if (str_contains($request->url(), '/ws/2/artist/')) {
                return Http::response([
                    'genres' => [['name' => 'alternative rock', 'count' => 5]],
                    'tags' => [],
                ]);
            }

            return Http::response([
                'artists' => [
                    ['id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Coldplay', 'score' => 100],
                ],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function song(User $user, array $overrides = []): Song
    {
        return $user->songs()->create([
            'slug' => 'yellow',
            'title' => 'Yellow',
            'artist' => 'Coldplay',
            'key' => 'B',
            'capo' => 0,
            'tags' => [],
            'source' => '',
            'body' => "B\nlook\n",
            'added' => '2026-10-24',
            ...$overrides,
        ]);
    }
}
