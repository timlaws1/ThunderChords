<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_requires_invite_when_configured(): void
    {
        config(['chords.invite_code' => 'friends-only']);

        $this->post('/register', [
            'name' => 'Tim',
            'email' => 'tim@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite' => 'wrong',
        ])->assertSessionHasErrors('invite');

        $this->post('/register', [
            'name' => 'Tim',
            'email' => 'tim@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite' => 'friends-only',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
    }

    public function test_register_closed_without_invite_code(): void
    {
        config(['chords.invite_code' => '']);

        $this->get('/register')->assertOk()->assertSee('Signup is closed');
        $this->post('/register', [
            'email' => 'a@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invite' => 'x',
        ])->assertSessionHasErrors('invite');
    }

    public function test_share_link_is_public_read_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/songs/import', [
            'title' => 'Shared',
            'artist' => 'Band',
            'body' => "Am\nla\n",
            'source' => 'https://example.com/x',
        ]);
        $song = $user->songs()->first();
        $song->update(['tags' => ['secret']]);
        $songId = $song->slug;

        $share = $this->actingAs($user)->postJson('/api/shares', [
            'type' => 'song',
            'id' => $songId,
            'expires' => '7d',
        ])->assertOk();

        $token = $share->json('token');
        auth()->logout();

        $this->get('/s/'.$token)->assertOk();
        $this->getJson('/api/shared/'.$token)
            ->assertOk()
            ->assertJsonPath('type', 'song')
            ->assertJsonPath('song.title', 'Shared')
            ->assertJsonMissingPath('song.tags')
            ->assertJsonMissingPath('song.source');
    }

    public function test_ssrf_blocked_for_localhost_import(): void
    {
        config(['chords.allow_local_import' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/songs/preview', ['url' => 'http://127.0.0.1/secret'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'That address cannot be imported. Use the paste option instead.']);
    }
}
