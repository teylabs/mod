<?php

namespace Tey\Mod\Discovery\Console;

use Illuminate\Console\Command;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryType;

final class DiscoveryCacheCommand extends Command
{
    protected $signature = 'mod:discovery-cache';

    protected $description = 'Scan the layout and cache the discovered providers, commands, listeners, subscribers and directories';

    public function handle(Discovery $discovery): int
    {
        $inventory = $discovery->writeCache();

        $this->components->info(sprintf(
            'Discovery cached in [%s]: %d providers, %d commands, %d listeners, %d subscribers, %d directories, %d rejected.',
            $discovery->cache()->path,
            count($inventory->ofType(DiscoveryType::Provider)),
            count($inventory->ofType(DiscoveryType::Command)),
            count($inventory->ofType(DiscoveryType::Listener)),
            count($inventory->ofType(DiscoveryType::Subscriber)),
            count($inventory->ofType(DiscoveryType::Directory)),
            count($inventory->rejections),
        ));

        return self::SUCCESS;
    }
}
