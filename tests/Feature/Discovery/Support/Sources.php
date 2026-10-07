<?php

namespace Tey\Mod\Tests\Feature\Discovery\Support;

/**
 * Hand-written class sources for fixture trees. {{ns}} is the fixture namespace.
 */
final class Sources
{
    public static function provider(string $namespace, string $class, string $binding): string
    {
        return <<<PHP
        <?php

        namespace {{ns}}\\{$namespace};

        use Illuminate\\Support\\ServiceProvider;

        class {$class} extends ServiceProvider
        {
            public function register(): void
            {
                \$this->app->instance('{$binding}', static::class);
            }
        }
        PHP;
    }

    public static function command(string $namespace, string $class, string $signature): string
    {
        return <<<PHP
        <?php

        namespace {{ns}}\\{$namespace};

        use Illuminate\\Console\\Command;

        class {$class} extends Command
        {
            protected \$signature = '{$signature}';

            public function handle(): int
            {
                return self::SUCCESS;
            }
        }
        PHP;
    }

    public static function plain(string $namespace, string $class, string $body = ''): string
    {
        return <<<PHP
        <?php

        namespace {{ns}}\\{$namespace};

        class {$class}
        {
            {$body}
        }
        PHP;
    }

    public static function event(string $namespace, string $class): string
    {
        return self::plain($namespace, $class, 'public function __construct(public string $id = "") {}');
    }

    /**
     * A listener recording every handled event in the container under fixture.handled.
     */
    public static function listener(string $namespace, string $class, string $method, string $eventType, string $uses = ''): string
    {
        return <<<PHP
        <?php

        namespace {{ns}}\\{$namespace};

        {$uses}

        class {$class}
        {
            public function {$method}({$eventType} \$event): void
            {
                \$handled = app()->bound('fixture.handled') ? app('fixture.handled') : [];
                \$handled[] = static::class.'@{$method}:'.\$event::class;
                app()->instance('fixture.handled', \$handled);
            }
        }
        PHP;
    }
}
