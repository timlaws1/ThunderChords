<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        $invite = (string) config('chords.invite_code', '');
        if ($invite === '') {
            return view('auth.register-closed');
        }

        return view('auth.register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $invite = (string) config('chords.invite_code', '');
        if ($invite === '') {
            throw ValidationException::withMessages([
                'invite' => 'Signup is closed.',
            ]);
        }

        $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'invite' => ['required', 'string'],
        ]);

        $provided = (string) $request->input('invite');
        if (! hash_equals($invite, $provided)) {
            usleep(800000);
            throw ValidationException::withMessages([
                'invite' => 'Wrong invite code.',
            ]);
        }

        $email = $request->string('email')->toString();
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect('/');
    }
}
