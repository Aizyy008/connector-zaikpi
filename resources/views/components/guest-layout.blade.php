@props(['title' => 'Sign in'])
<!DOCTYPE html>
<html lang="en" data-theme="dark" class="bg-bg text-text font-sans">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('app-theme');
                document.documentElement.dataset.theme = (t === 'light' || t === 'dark') ? t : 'dark';
            } catch (e) { document.documentElement.dataset.theme = 'dark'; }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased">
    <div class="min-h-screen grid place-items-center px-4"
         style="background: radial-gradient(circle at top right, color-mix(in srgb, var(--blue) 12%, transparent), transparent 22%), var(--bg);">
        <div class="w-full max-w-md">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="grid place-items-center w-11 h-11 rounded-2xl text-white font-extrabold shadow-lg"
                         style="background: linear-gradient(135deg, var(--blue), var(--purple));">APP</div>
                    <div>
                        <div class="text-xl font-extrabold leading-none">{{ config('app.name') }}</div>
                        <div class="text-xs text-muted font-semibold mt-1">Admin Panel</div>
                    </div>
                </div>
                <x-theme-toggle />
            </div>

            <div class="rounded-[var(--radius-card)] border border-border bg-panel shadow-xl overflow-hidden">
                {{ $slot }}
            </div>

            <p class="text-center text-xs text-muted mt-6">
                &copy; {{ date('Y') }} {{ config('app.name') }}. Protected control panel.
            </p>
        </div>
    </div>
</body>
</html>
