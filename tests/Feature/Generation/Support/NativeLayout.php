<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

use Tey\Mod\Layout\FileType;
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
            ->mounts('app', 'App\\', 'app', fn (Root $root) => $root
                ->generates('cast', in: 'Casts')
                ->generates('channel', in: 'Broadcasting')
                ->generates('class', in: '', priority: 1)
                ->generates('enum', in: '', priority: 2)
                ->generates('exception', in: 'Exceptions')
                ->generates('interface', in: '', priority: 3)
                ->generates('job', in: 'Jobs')
                ->generates('job-middleware', in: 'Jobs/Middleware')
                ->generates('mail', in: 'Mail')
                ->generates('middleware', in: 'Http/Middleware')
                ->generates('notification', in: 'Notifications')
                ->generates('observer', in: 'Observers')
                ->generates('resource', in: 'Http/Resources')
                ->generates('rule', in: 'Rules')
                ->generates('scope', in: 'Models/Scopes')
                ->generates('trait', in: '', priority: 4))
            ->mounts('tests', 'Tests\\', 'tests', fn (Root $root) => $root
                ->generates('test', in: 'Feature/{group?}', nested: true))
            ->mounts('config', null, 'config', fn (Root $root) => $root
                ->generates('config', in: '', using: fn (FileType $kind) => $kind->file()));
    }
}
