<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;

/**
 * A mod:* command another built-in layout has, in an application whose layout
 * lacks it: says which layouts have it and how to add the file type, instead
 * of "Command is not defined". Hidden from the list; any command of the same
 * name from the application or a package replaces it.
 *
 * @internal registered by the service provider
 */
final class OtherLayoutCommand extends Command
{
    /**
     * @param  list<string>  $layouts  the built-in layouts that have the command
     */
    public function __construct(string $name, private readonly string $kindId, private readonly array $layouts, private readonly string $layout)
    {
        $this->name = $name;
        $this->description = 'Not a command of the '.$layout.' layout';

        parent::__construct();

        $this->setHidden();
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        if (in_array($this->kindId, ['page', 'view'], true)) {
            $path = $this->kindId === 'page' ? 'pages' : 'views';
            $this->components->error($this->getName().' needs a frontend folder. Declare ->frontend('.$path.': ...) on the layout in a service provider. Nothing was written.');

            return self::FAILURE;
        }

        $layouts = $this->layouts;
        $last = array_pop($layouts);
        $names = $layouts === [] ? "The {$last} layout has it." : 'The '.implode(', ', $layouts)." and {$last} layouts have it.";

        $this->components->error("{$this->getName()} is not a command of the {$this->layout} layout. {$names}");
        $this->line("  To add it, declare the file type in a service provider: Mod::layout('{$this->layout}')->generates('{$this->kindId}', in: '<folder>'). Or switch layouts in config/mod.php.");

        return self::FAILURE;
    }
}
