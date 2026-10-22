<x-guest-layout>
    <h1 class="brand">Chords</h1>
    <p class="muted">Sign in to your chord library</p>

    <form method="POST" action="{{ route('login') }}" class="grid gap-3">
        @csrf

        <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Email" required autofocus autocomplete="username">
        <input id="password" type="password" name="password" placeholder="Password" required autocomplete="current-password">

        <label class="flex items-center gap-2 text-sm text-mute">
            <input type="checkbox" name="remember">
            Remember me
        </label>

        <button class="primary">Sign in</button>

        @if ($errors->any())
            <p class="err">{{ $errors->first() }}</p>
        @endif

        @if (config('chords.invite_code'))
            <p class="muted"><a class="underline decoration-chord/40 hover:decoration-chord" href="{{ route('register') }}">Create an account</a></p>
        @endif
    </form>
</x-guest-layout>
