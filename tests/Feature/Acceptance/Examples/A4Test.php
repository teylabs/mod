<?php

use Illuminate\Console\Command;
use Tey\Mod\Tests\Support\BoostCoverage;

it('A4 names the exact command and missing option in the coverage failure', function () {
    $command = new class extends Command
    {
        protected $signature = 'mod:index-filter {name} {--fields=}';
    };
    expect(BoostCoverage::missing([$command], "## mod:index-filter\n", ''))
        ->toBe(["mod:index-filter --fields isn't mentioned in resources/boost/skills/mod-development/SKILL.md."]);
});
