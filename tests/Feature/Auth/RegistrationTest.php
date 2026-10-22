<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered_when_invite_set(): void
    {
        config(['chords.invite_code' => 'friends-only']);

        $this->get('/register')->assertStatus(200);
    }

    public function test_new_users_can_register_with_invite(): void
    {
        config(['chords.invite_code' => 'friends-only']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite' => 'friends-only',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/');
    }
}
