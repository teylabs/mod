<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;

/** @internal hidden diagnostic placeholder, like OtherLayoutCommand; generates nothing */
final class DisabledScaffoldCommand extends Command
{
    public function __construct(string $name, private readonly string $problem)
    {
        $this->name = 'mod:'.$name;
        parent::__construct();
        $this->setHidden();
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->components->warn($this->problem);
        $this->components->error('Command "'.$this->getName().'" is not defined.');

        return self::FAILURE;
    }
}
