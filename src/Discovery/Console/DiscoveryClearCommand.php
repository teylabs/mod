<?php

namespace Tey\Mod\Discovery\Console;

use Illuminate\Console\Command;
use Tey\Mod\Discovery\Discovery;

final class DiscoveryClearCommand extends Command
{
    protected $signature = 'mod:discovery-clear';

    protected $description = 'Remove the discovery cache file';

    public function handle(Discovery $discovery): int
    {
        $discovery->clearCache()
            ? $this->components->info('Discovery cache cleared.')
            : $this->components->info('No discovery cache to clear.');

        return self::SUCCESS;
    }
}
