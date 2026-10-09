<?php

namespace Tey\Mod\Tests\Feature\Acceptance\Examples\Support;

use Illuminate\Contracts\Console\Kernel;
use Mockery\Matcher\Closure;
use Pest\TestSuite;
use Tey\Mod\Tests\Feature\Generation\Support\CommandResult;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\TestCase;

final class TemplateScenario
{
    public const CLASS_STUB = "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n}\n";

    public static function testCase(): TestCase
    {
        $case = TestSuite::getInstance()->test;
        if (! $case instanceof TestCase) {
            throw new \LogicException('Template acceptance tests need Testbench.');
        }

        return $case;
    }

    public static function output(Workspace $workspace, string $expected): Closure
    {
        return \Mockery::on(static fn (string $output): bool => str_contains((new CommandResult(0, $output, $workspace->root->path))->normalisedOutput(), $expected));
    }

    public static function tool(Workspace $workspace, string $layout = 'modules'): void
    {
        putenv('COLUMNS=72');
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        config()->set('mod.layout', $layout);
        $workspace->write('stubs/mod/'.($layout === 'laravel' ? '' : '@module/').'Tools/tool.stub', self::CLASS_STUB);
        foreach (['Agents', 'Knowledge'] as $group) {
            mkdir($workspace->root->path('app/Modules/'.$group.'/Tools'), 0700, true);
        }
    }

    public static function webhook(Workspace $workspace, bool $existingSource = false): void
    {
        self::tool($workspace);
        $workspace->write('stubs/mod/@module/Webhooks/[source]/webhook.stub', str_replace('class {{ class }}', "// {{ source }}\nclass {{ class }}", self::CLASS_STUB));
        if ($existingSource) {
            mkdir($workspace->root->path('app/Modules/Knowledge/Webhooks/Drive'), 0700, true);
        }
    }

    public static function content(string $namespace, string $class): string
    {
        return str_replace(['{{ namespace }}', '{{ class }}'], [$namespace, $class], self::CLASS_STUB);
    }
}
