<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use Tey\Mod\Layout\Kind;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;

/**
 * Phase-one fixture: extend the shipped Laravel layout with native placements.
 * The built-in kind declarations are deliberately left for the next phase.
 */
final class NativeLayout
{
    public static function extend(): void
    {
        app(LayoutRegistry::class)->layout('laravel')
            ->root('app', 'App\\', 'app', fn (Root $root) => $root
                ->kind('cast', in: 'Casts')
                ->kind('channel', in: 'Broadcasting')
                ->kind('class', in: '', priority: 1)
                ->kind('enum', in: '', priority: 2)
                ->kind('exception', in: 'Exceptions')
                ->kind('interface', in: '', priority: 3)
                ->kind('job', in: 'Jobs')
                ->kind('job-middleware', in: 'Jobs/Middleware')
                ->kind('mail', in: 'Mail')
                ->kind('middleware', in: 'Http/Middleware')
                ->kind('notification', in: 'Notifications')
                ->kind('observer', in: 'Observers')
                ->kind('resource', in: 'Http/Resources')
                ->kind('rule', in: 'Rules')
                ->kind('scope', in: 'Models/Scopes')
                ->kind('trait', in: '', priority: 4))
            ->root('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->kind('test', in: 'Feature/{group?}', nested: true))
            ->root('config', null, 'config', fn (Root $root) => $root
                ->kind('config', in: '', using: fn (Kind $kind) => $kind->file()));
    }
}
