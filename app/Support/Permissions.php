<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical permission catalog — the single source of truth used by both the
 * seeder (to create rows) and AppServiceProvider (to register gates). Kept in
 * code so gate registration never depends on a DB query at boot time.
 *
 * See docs/03-permissions-model.md.
 */
class Permissions
{
    /** @var array<string, array<int, string>> group => slugs */
    public const CATALOG = [
        'workspaces' => ['workspaces.view', 'workspaces.manage'],
        'users' => ['users.view', 'users.manage', 'roles.manage'],
        'connectors' => ['connectors.view', 'connectors.write', 'connectors.test'],
        'credentials' => ['credentials.view', 'credentials.manage'],
        'modules' => ['modules.view', 'modules.manage'],
        'capabilities' => ['capabilities.view', 'capabilities.manage'],
        'routing' => ['routing.view', 'routing.manage'],
        'mappings' => ['mappings.view', 'mappings.manage'],
        'canonical' => ['canonical.view', 'canonical.manage'],
        'webhooks' => ['webhooks.view', 'webhooks.manage'],
        'payloads' => ['payloads.view'],
        'flows' => ['flows.view', 'flows.manage', 'flows.execute'],
        'queue' => ['queue.view', 'queue.retry'],
        'approvals' => ['approvals.view', 'approvals.decide'],
        'logs' => ['logs.view'],
        'audit' => ['audit.view'],
        'settings' => ['settings.view', 'settings.manage'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_merge(...array_values(self::CATALOG));
    }

    /** @return list<string> all "*.view" slugs */
    public static function viewSlugs(): array
    {
        return array_values(array_filter(self::slugs(), fn ($s) => Str::endsWith($s, '.view')));
    }

    public static function label(string $slug): string
    {
        [$group, $action] = array_pad(explode('.', $slug), 2, '');

        return Str::headline($group).' — '.Str::headline($action);
    }

    public static function groupOf(string $slug): string
    {
        return Str::before($slug, '.');
    }
}
