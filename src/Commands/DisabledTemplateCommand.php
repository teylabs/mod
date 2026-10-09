<?php

namespace Tey\Mod\Commands;

use Illuminate\Console\Command;

/** @internal hidden diagnostic placeholder, like OtherLayoutCommand; generates nothing */
final class DisabledTemplateCommand extends Command
{
    public function __construct(string $name, private readonly string $problem)
    {
        $this->name = $name;
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
