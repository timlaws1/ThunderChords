<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_songs(): void
    {
        $this->getJson('/api/songs')->assertUnauthorized();
    }

    public function test_import_paste_and_list_songs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/import', [
                'title' => 'Hello',
                'artist' => 'Band',
                'key' => 'Am',
                'capo' => 0,
                'body' => "Am  F\nhello\n",
            ])
            ->assertOk()
            ->assertJsonStructure(['id']);

        $this->actingAs($user)
            ->getJson('/api/songs')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Hello', 'artist' => 'Band']);

        $this->assertSame([], $user->songs()->first()->tags);
    }

    public function test_import_stores_tags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/import', [
                'title' => 'Tagged',
                'artist' => 'Band',
                'body' => "C\nla\n",
                'tags' => 'pop, acoustic',
            ])
            ->assertOk();

        $this->assertSame(['pop', 'acoustic'], $user->songs()->first()->tags);
    }

    public function test_duplicate_source_url_returns_existing_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/songs/import', [
            'title' => 'One',
            'artist' => 'A',
            'body' => "C\nla\n",
            'source' => 'https://example.com/song',
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/songs/import', [
            'title' => 'Two',
            'artist' => 'B',
            'body' => "G\nla\n",
            'source' => 'https://example.com/song',
        ])->assertOk()->assertJson(['duplicate' => true]);
    }

    public function test_prefs_round_trip(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/songs/import', [
            'title' => 'Prefs',
            'artist' => 'X',
            'body' => "C\nx\n",
        ]);
        $id = $user->songs()->first()->slug;

        $this->actingAs($user)
            ->putJson('/api/prefs/song:'.$id, ['t' => 2, 'c' => 3, 'speed' => 8, 'size' => 20, 'flats' => true])
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/prefs/song:'.$id)
            ->assertOk()
            ->assertJsonPath('prefs.t', 2)
            ->assertJsonPath('prefs.c', 3)
            ->assertJsonPath('prefs.flats', true);
    }

    public function test_setlist_crud(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/songs/import', [
            'title' => 'Song',
            'artist' => 'A',
            'body' => "C\nla\n",
        ]);
        $songId = $user->songs()->first()->slug;

        $create = $this->actingAs($user)->postJson('/api/setlists', [
            'name' => 'Gig',
            'items' => [['songId' => $songId, 't' => 1, 'c' => 0]],
        ])->assertOk();

        $slug = $create->json('id');
        $this->actingAs($user)->getJson('/api/setlists/'.$slug.'/full')
            ->assertOk()
            ->assertJsonPath('type', 'setlist')
            ->assertJsonPath('setlist.items.0.songId', $songId);
    }
}
