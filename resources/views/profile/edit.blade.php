<x-app-layout>
    <div class="tc-card max-w-xl grid gap-6">
        <div>
            <h2 class="font-brand text-xl font-bold m-0">{{ Auth::user()->email }}</h2>
            <p class="muted mt-1 mb-0">Manage your account</p>
        </div>

        <div>
            @include('profile.partials.update-profile-information-form')
        </div>

        <div>
            @include('profile.partials.update-password-form')
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn">Sign out</button>
        </form>
    </div>
</x-app-layout>
