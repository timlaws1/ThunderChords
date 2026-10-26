<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MedleySuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lastfm.key' => null]);
    }

    public function test_guest_suggestions_return_401(): void
    {
        $this->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertUnauthorized();
    }

    public function test_suggestions_return_400_without_songs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => []])
            ->assertBadRequest()
            ->assertJsonPath('error', 'Add a song first.');
    }

    public function test_suggestions_rank_shared_genre_and_artist(): void
    {
        $user = User::factory()->create();
        $this->song($user, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'B', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'clocks', 'title' => 'Clocks', 'artist' => 'Coldplay', 'key' => 'Eb', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'shiver', 'title' => 'Shiver', 'artist' => 'Coldplay', 'key' => 'A', 'tags' => []]);
        $this->song($user, ['slug' => 'wonderwall', 'title' => 'Wonderwall', 'artist' => 'Oasis', 'key' => 'F#m', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'yesterday', 'title' => 'Yesterday', 'artist' => 'The Beatles', 'key' => 'F', 'tags' => ['pop']]);
        $this->song($user, ['slug' => 'campfire', 'title' => 'Campfire', 'artist' => 'Other', 'key' => 'B', 'tags' => []]);

        $response = $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertOk();

        $this->assertSame(
            ['clocks', 'shiver', 'wonderwall'],
            array_column($response->json('suggestions'), 'id'),
        );
        $this->assertSame(
            'Same genre as Yellow: alternative rock · Also by Coldplay',
            $response->json('suggestions.0.reason'),
        );
        $this->assertSame('Also by Coldplay', $response->json('suggestions.1.reason'));
        $this->assertSame('Same genre as Yellow: alternative rock', $response->json('suggestions.2.reason'));
    }

    public function test_suggestions_skip_songs_already_in_the_medley(): void
    {
        $user = User::factory()->create();
        $this->song($user, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'B', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'clocks', 'title' => 'Clocks', 'artist' => 'Coldplay', 'key' => 'Eb', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'wonderwall', 'title' => 'Wonderwall', 'artist' => 'Oasis', 'key' => 'F#m', 'tags' => ['alternative rock']]);

        $response = $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow', 'clocks']])
            ->assertOk();

        $this->assertSame(['wonderwall'], array_column($response->json('suggestions'), 'id'));
    }

    public function test_suggestions_return_400_when_the_song_belongs_to_someone_else(): void
    {
        $owner = User::factory()->create();
        $this->song($owner, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'B', 'tags' => ['rock']]);
        $this->song($owner, ['slug' => 'clocks', 'title' => 'Clocks', 'artist' => 'Coldplay', 'key' => 'Eb', 'tags' => ['rock']]);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertBadRequest()
            ->assertJsonPath('error', 'Add a song first.');
    }

    public function test_suggestions_ignore_another_users_library(): void
    {
        $owner = User::factory()->create();
        $this->song($owner, ['slug' => 'clocks', 'title' => 'Clocks', 'artist' => 'Coldplay', 'key' => 'Eb', 'tags' => ['rock']]);
        $other = User::factory()->create();
        $this->song($other, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'B', 'tags' => ['rock']]);

        $this->actingAs($other)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertOk()
            ->assertJsonPath('suggestions', []);
    }

    public function test_suggestions_prefer_a_similar_track_when_lastfm_matches(): void
    {
        config(['services.lastfm.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'ws.audioscrobbler.com/*' => Http::response([
                'similartracks' => [
                    'track' => [
                        ['name' => 'Wonderwall', 'artist' => ['name' => 'Oasis']],
                        ['name' => 'Fix You', 'artist' => ['name' => 'Coldplay']],
                    ],
                ],
            ]),
        ]);
        $user = User::factory()->create();
        $this->song($user, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'B', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'clocks', 'title' => 'Clocks', 'artist' => 'Coldplay', 'key' => 'Eb', 'tags' => ['alternative rock']]);
        $this->song($user, ['slug' => 'wonderwall', 'title' => 'Wonderwall', 'artist' => 'Oasis', 'key' => 'F#m', 'tags' => ['alternative rock']]);

        $response = $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertOk();

        $this->assertSame('wonderwall', $response->json('suggestions.0.id'));
        $this->assertSame(
            'Often played with Yellow · Same genre as Yellow: alternative rock',
            $response->json('suggestions.0.reason'),
        );
        $this->assertNotContains('Fix You', array_column($response->json('suggestions'), 'title'));
    }

    public function test_suggestions_rank_a_song_closer_to_the_target_key_first(): void
    {
        $user = User::factory()->create();
        $this->song($user, ['slug' => 'yellow', 'title' => 'Yellow', 'artist' => 'Coldplay', 'key' => 'C', 'tags' => ['rock']]);
        $this->song($user, ['slug' => 'near', 'title' => 'Near Song', 'artist' => 'Someone', 'key' => 'G', 'tags' => ['rock']]);
        $this->song($user, ['slug' => 'far', 'title' => 'Far Song', 'artist' => 'Someone Else', 'key' => 'F#', 'tags' => ['rock']]);

        $withoutTarget = $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow']])
            ->assertOk();
        $withTarget = $this->actingAs($user)
            ->postJson('/api/medleys/suggestions', ['songs' => ['yellow'], 'targetKey' => 'F#'])
            ->assertOk();

        $this->assertSame('near', $withoutTarget->json('suggestions.0.id'));
        $this->assertSame('far', $withTarget->json('suggestions.0.id'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function song(User $user, array $overrides): Song
    {
        return $user->songs()->create([
            'slug' => 'song',
            'title' => 'Song',
            'artist' => 'Artist',
            'key' => 'C',
            'capo' => 0,
            'tags' => [],
            'source' => '',
            'body' => "C\nla\n",
            'added' => '2026-10-24',
            ...$overrides,
        ]);
    }
}
