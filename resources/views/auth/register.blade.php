<x-guest-layout>
    <h1 class="brand">ThunderChords</h1>
    <p class="muted">Create an account with an invite code</p>

    <form method="POST" action="{{ route('register') }}" class="grid gap-3">
        @csrf

        <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="Email" required autofocus autocomplete="username">
        <input id="name" type="text" name="name" value="{{ old('name') }}" placeholder="Name (optional)" autocomplete="name">
        <input id="password" type="password" name="password" placeholder="Password (8+ characters)" required autocomplete="new-password">
        <input id="password_confirmation" type="password" name="password_confirmation" placeholder="Confirm password" required autocomplete="new-password">
        <input id="invite" type="text" name="invite" value="{{ old('invite') }}" placeholder="Invite code" required autocomplete="off">

        <button class="primary">Create account</button>

        @if ($errors->any())
            <p class="err">{{ $errors->first() }}</p>
        @endif

        <p class="muted"><a class="underline decoration-chord/40 hover:decoration-chord" href="{{ route('login') }}">Already have an account?</a></p>
    </form>
</x-guest-layout>
