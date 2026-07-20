<x-guest-layout title="Sign in">
    <div class="p-8">
        <h1 class="text-2xl font-extrabold tracking-tight">Sign in</h1>
        <p class="text-sm text-muted mt-1">Access the {{ config('app.name') }} control panel.</p>

        @if ($errors->any())
            <div class="mt-5 rounded-xl border px-4 py-3 text-sm"
                 style="background: color-mix(in srgb, var(--red) 12%, transparent); color: var(--red); border-color: color-mix(in srgb, var(--red) 25%, transparent);">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-xs font-bold uppercase tracking-wide text-muted mb-2">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username"
                       class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-3 text-text
                              outline-none focus:border-blue focus:ring-2 focus:ring-blue/30"
                       placeholder="you@example.com">
            </div>

            <div>
                <label for="password" class="block text-xs font-bold uppercase tracking-wide text-muted mb-2">Password</label>
                <input id="password" name="password" type="password"
                       required autocomplete="current-password"
                       class="w-full rounded-xl border border-border bg-panel-2 px-3.5 py-3 text-text
                              outline-none focus:border-blue focus:ring-2 focus:ring-blue/30"
                       placeholder="••••••••">
            </div>

            <label class="flex items-center gap-2 text-sm text-muted select-none">
                <input type="checkbox" name="remember" value="1" class="rounded border-border">
                Remember me on this device
            </label>

            <button type="submit"
                    class="w-full rounded-xl py-3 font-extrabold text-white shadow-lg transition hover:-translate-y-0.5"
                    style="background: linear-gradient(135deg, var(--blue), var(--purple));">
                Sign in
            </button>
        </form>
    </div>
</x-guest-layout>
