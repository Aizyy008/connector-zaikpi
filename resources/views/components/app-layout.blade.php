@props(['title' => 'Dashboard', 'active' => 'dashboard'])
@php
    // Sidebar model. Real routes are gated by permission; later-milestone modules
    // render as disabled placeholders (honest roadmap).
    $nav = [
        ['key' => 'dashboard',  'label' => 'Dashboard',            'route' => 'admin.dashboard',      'can' => null,               'milestone' => 1],
        ['key' => 'workspaces', 'label' => 'Workspaces',           'route' => 'admin.workspaces.index','can' => 'workspaces.view',  'milestone' => 2],
        ['key' => 'users',      'label' => 'Users',                'route' => 'admin.users.index',    'can' => 'users.view',       'milestone' => 2],
        ['key' => 'roles',      'label' => 'Roles & Permissions',  'route' => 'admin.roles.index',    'can' => 'users.view',       'milestone' => 2],
        ['key' => 'connectors', 'label' => 'Connectors',           'route' => 'admin.connectors.index','can' => 'connectors.view', 'milestone' => 3],
        ['key' => 'modules',    'label' => 'Modules',              'route' => 'admin.modules.index',  'can' => 'modules.view',     'milestone' => 3],
        ['key' => 'webhooks',   'label' => 'Webhooks',             'route' => 'admin.webhooks.index', 'can' => 'webhooks.view',    'milestone' => 4],
        ['key' => 'payloads',   'label' => 'Payload Logs',         'route' => 'admin.payloads.index', 'can' => 'payloads.view',    'milestone' => 4],
        ['key' => 'mappings',   'label' => 'Mappings',             'route' => 'admin.mappings.index', 'can' => 'mappings.view',    'milestone' => 4],
        ['key' => 'flows',      'label' => 'Flows / Automations',  'route' => 'admin.flows.index',    'can' => 'flows.view',       'milestone' => 5],
        ['key' => 'queue',      'label' => 'Queue & Logs',         'route' => 'admin.queue.index',    'can' => 'queue.view',       'milestone' => 5],
        ['key' => 'audit',      'label' => 'Audit Trail',          'route' => 'admin.audit.index',    'can' => 'audit.view',       'milestone' => 6],
    ];
@endphp
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
<body class="min-h-screen antialiased"
      style="background: radial-gradient(circle at top right, color-mix(in srgb, var(--blue) 12%, transparent), transparent 22%), var(--bg);">
<div class="grid min-h-screen grid-cols-1 md:grid-cols-[280px_minmax(0,1fr)]">
    {{-- Sidebar --}}
    <aside class="hidden md:flex flex-col gap-2 p-4 text-nav-text sticky top-0 h-screen overflow-auto border-r border-white/5"
           style="background: linear-gradient(180deg, var(--nav), var(--nav-2));">
        <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[.03] p-3">
            <div class="grid place-items-center w-10 h-10 rounded-xl text-white font-extrabold"
                 style="background: linear-gradient(135deg, var(--blue), var(--purple));">APP</div>
            <div>
                <div class="text-lg font-extrabold leading-none">{{ config('app.name') }}</div>
                <div class="text-[11px] text-nav-muted font-semibold mt-1">Admin Panel</div>
            </div>
        </div>

        {{-- Workspace switcher --}}
        @isset($availableWorkspaces)
            <form method="POST" action="{{ route('admin.workspace.switch') }}" class="mt-1">
                @csrf
                <label class="block text-[11px] uppercase tracking-widest font-extrabold text-nav-muted px-1 mb-1.5">Workspace</label>
                <select name="workspace_id" onchange="this.form.submit()"
                        class="w-full rounded-xl border border-white/10 bg-white/[.04] px-3 py-2 text-sm font-semibold text-nav-text outline-none">
                    @foreach ($availableWorkspaces as $ws)
                        <option value="{{ $ws->id }}" @selected(isset($currentWorkspace) && $currentWorkspace->id === $ws->id)>
                            {{ $ws->name }} ({{ ucfirst($ws->environment) }})
                        </option>
                    @endforeach
                </select>
            </form>
        @endisset

        <div class="text-[11px] uppercase tracking-widest font-extrabold text-nav-muted px-2 mt-3 mb-1">
            Main Navigation
        </div>
        <nav class="flex flex-col gap-1">
            @foreach ($nav as $item)
                @php
                    $isActive = $item['key'] === $active;
                    $visible = $item['route'] && (! $item['can'] || auth()->user()->can($item['can']));
                @endphp
                @if ($visible)
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 font-semibold transition
                              {{ $isActive ? 'bg-blue/15 outline outline-1 outline-blue/30' : 'hover:bg-white/5' }}">
                        <span>{{ $item['label'] }}</span>
                    </a>
                @elseif (! $item['route'])
                    <span class="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 font-semibold text-nav-muted/70 cursor-not-allowed"
                          title="Arrives in Milestone {{ $item['milestone'] }}">
                        <span>{{ $item['label'] }}</span>
                        <span class="text-[10px] font-bold uppercase rounded-full border border-white/10 px-2 py-0.5">M{{ $item['milestone'] }}</span>
                    </span>
                @endif
            @endforeach
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="mt-auto pt-4">
            @csrf
            <div class="rounded-xl border border-white/10 bg-white/[.03] p-3">
                <div class="text-sm font-bold truncate">{{ auth()->user()->name }}</div>
                <div class="text-[11px] text-nav-muted truncate">{{ auth()->user()->email }}</div>
                @if (auth()->user()->is_super_admin)
                    <div class="mt-1 text-[10px] font-extrabold uppercase tracking-wide text-blue">Super Admin</div>
                @endif
                <button type="submit"
                        class="mt-3 w-full rounded-lg border border-white/10 bg-white/[.04] px-3 py-2 text-sm font-bold hover:bg-white/[.08] transition">
                    Sign out
                </button>
            </div>
        </form>
    </aside>

    {{-- Main --}}
    <main class="p-4 md:p-6 grid gap-5 content-start">
        {{-- Mobile top bar: brand + hamburger --}}
        <div class="md:hidden flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="grid place-items-center w-9 h-9 rounded-xl text-white font-extrabold"
                     style="background: linear-gradient(135deg, var(--blue), var(--purple));">APP</div>
                <div class="font-extrabold">{{ config('app.name') }}</div>
            </div>
            <button type="button" id="mobile-menu-btn" aria-label="Toggle navigation menu" aria-expanded="false"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-panel px-3 py-2 text-sm font-bold">
                <span id="mobile-menu-icon" class="text-base leading-none">☰</span>
                <span>Menu</span>
            </button>
        </div>

        {{-- Mobile navigation drawer (toggled by the hamburger) --}}
        <nav id="mobile-menu" class="md:hidden hidden rounded-2xl border border-white/10 p-3 text-nav-text"
             style="background: linear-gradient(180deg, var(--nav), var(--nav-2));">
            @isset($availableWorkspaces)
                <form method="POST" action="{{ route('admin.workspace.switch') }}" class="mb-2">
                    @csrf
                    <label class="block text-[11px] uppercase tracking-widest font-extrabold text-nav-muted px-1 mb-1.5">Workspace</label>
                    <select name="workspace_id" onchange="this.form.submit()"
                            class="w-full rounded-xl border border-white/10 bg-white/[.04] px-3 py-2 text-sm font-semibold text-nav-text outline-none">
                        @foreach ($availableWorkspaces as $ws)
                            <option value="{{ $ws->id }}" @selected(isset($currentWorkspace) && $currentWorkspace->id === $ws->id)>
                                {{ $ws->name }} ({{ ucfirst($ws->environment) }})
                            </option>
                        @endforeach
                    </select>
                </form>
            @endisset

            <div class="flex flex-col gap-1">
                @foreach ($nav as $item)
                    @php
                        $isActive = $item['key'] === $active;
                        $visible = $item['route'] && (! $item['can'] || auth()->user()->can($item['can']));
                    @endphp
                    @if ($visible)
                        <a href="{{ route($item['route']) }}"
                           class="rounded-xl px-3 py-2.5 font-semibold {{ $isActive ? 'bg-blue/15 outline outline-1 outline-blue/30' : 'hover:bg-white/5' }}">
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-3 pt-3 border-t border-white/10">
                @csrf
                <div class="text-sm font-bold truncate">{{ auth()->user()->name }}</div>
                <div class="text-[11px] text-nav-muted truncate mb-2">{{ auth()->user()->email }}</div>
                <button type="submit"
                        class="w-full rounded-lg border border-white/10 bg-white/[.04] px-3 py-2 text-sm font-bold hover:bg-white/[.08] transition">
                    Sign out
                </button>
            </form>
        </nav>

        {{-- Topbar --}}
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <div class="text-sm text-muted mb-1">{{ $breadcrumb ?? config('app.name') }}</div>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight">{{ $title }}</h1>
                @isset($subtitle)
                    <p class="mt-2 text-muted max-w-3xl leading-relaxed">{{ $subtitle }}</p>
                @endisset
            </div>
            <div class="flex items-center gap-2.5 flex-wrap justify-end">
                {{ $actions ?? '' }}
                <x-theme-toggle />
            </div>
        </div>

        <x-flash />

        {{ $slot }}
    </main>
</div>

<script>
    (function () {
        var btn = document.getElementById('mobile-menu-btn');
        var menu = document.getElementById('mobile-menu');
        var icon = document.getElementById('mobile-menu-icon');
        if (! btn || ! menu) {
            return;
        }
        btn.addEventListener('click', function () {
            var isOpen = ! menu.classList.toggle('hidden');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (icon) {
                icon.textContent = isOpen ? '✕' : '☰';
            }
        });
    })();
</script>
</body>
</html>
