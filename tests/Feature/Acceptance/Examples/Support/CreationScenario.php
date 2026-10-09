<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Support;

use Illuminate\Contracts\Console\Kernel;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;

final class CreationScenario
{
    public static function setup(Workspace $workspace, string $layout = 'modules'): void
    {
        putenv('COLUMNS=72');
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        config()->set('mod.layout', $layout);
        foreach (['Agents', 'Knowledge'] as $group) {
            $folder = match ($layout) {
                'ddd' => 'src/Domain/'.$group.'/Workflows',
                'type-first' => 'app/Tools/'.$group,
                'features' => 'app/'.$group.'/Tools',
                'slices' => 'app/'.$group.'/IndexDocument',
                default => 'app/Modules/'.$group.'/Tools',
            };
            mkdir($workspace->root->path($folder), 0700, true);
        }
    }

    public static function rebootConsole(): void
    {
        app()->forgetInstance(CompiledLayout::class);
        $kernel = app(Kernel::class);
        $kernel->setArtisan(null);
    }

    public static function fixture(string $name): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__.'/../../../../Fixtures/Templates/Creation/'.$name.'.txt'));
    }

    /** @param array<string, string> $details */
    public static function output(string $path, array $details, bool $extracted = false, ?string $class = null): string
    {
        $out = $class === null ? '' : "\n   INFO  Using {$class}.  \n\n";
        $out .= ($class === null ? "\n" : '')."   INFO  Template [stubs/mod/{$path}.stub] created.  \n\n";
        foreach ($details as $label => $value) {
            $out .= '  '.$label.' '.str_repeat('.', max(1, 66 - strlen($label) - strlen($value))).' '.$value."  \n";
        }
        $out .= "\n";
        if ($extracted) {
            $out .= "  Edit the template to generalize the rest.\n";
        }

        return $out;
    }

    /** @param list<string> $messages */
    public static function errors(array $messages): string
    {
        return "\n".implode('', array_map(static fn (string $message): string => ltrim(self::error($message), "\n"), $messages));
    }

    public static function error(string $message): string
    {
        if (! preg_match('/[.!?:]$/', $message)) {
            $message .= '.';
        }

        return "\n   ERROR  {$message}  \n\n";
    }
}
