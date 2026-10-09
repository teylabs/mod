<?php

namespace Tey\Mod\Templates;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal reports each scan diagnostic once, including failed command lookup. */
final class TemplateDiagnostics
{
    /** @var list<string> */
    private array $reported = [];

    public function __construct(private readonly TemplateCatalog $catalog) {}

    public function report(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getFirstArgument() === 'mod:list') {
            return;
        }
        $components = new Factory(new OutputStyle($input, $output));
        foreach ($this->catalog->skipped() as $path => $fix) {
            if (in_array($fix, $this->reported, true)) {
                continue;
            }
            $this->reported[] = $fix;
            $components->warn("Skipped template [{$path}]: {$fix}");
        }
        foreach ($this->catalog->notices() as $notice) {
            if (! in_array($notice, $this->reported, true)) {
                $this->reported[] = $notice;
                $components->info($notice);
            }
        }
    }
}
