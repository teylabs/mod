<?php

namespace Tey\Mod\Discovery\Console;

use Illuminate\Console\Command;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Discovery\RejectionReason;

final class DiscoveryCacheCommand extends Command
{
    protected $signature = 'mod:cache';

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

        $this->explainRejections($inventory);

        return self::SUCCESS;
    }

    /**
     * What "rejected" means, grouped by reason with whether it needs a look;
     * -v lists each file.
     */
    private function explainRejections(Inventory $inventory): void
    {
        if ($inventory->rejections === []) {
            return;
        }

        $counts = [];

        foreach ($inventory->rejections as $rejection) {
            $counts[$rejection->reason->value] = ($counts[$rejection->reason->value] ?? 0) + 1;
        }

        $parts = [];

        foreach (RejectionReason::cases() as $reason) {
            if (isset($counts[$reason->value])) {
                $parts[] = $counts[$reason->value].' '.self::reason($reason).' ('.self::advice($reason, $counts[$reason->value]).')';
            }
        }

        $verbose = $this->output->isVerbose();
        $this->line('  Rejected files were found but not registered: '.implode(', ', $parts).'.'.($verbose ? '' : ' Run with -v to list them.'));

        if ($verbose) {
            foreach ($inventory->rejections as $rejection) {
                $this->line("  {$rejection->path}: ".self::reason($rejection->reason)." ({$rejection->detail})");
            }
        }
    }

    private static function reason(RejectionReason $reason): string
    {
        return match ($reason) {
            RejectionReason::NotOwned => 'placed by no file type',
            RejectionReason::Ineligible => 'in a discovered folder but not a provider, command, listener or subscriber',
            RejectionReason::Ambiguous => 'placed by more than one file type',
            RejectionReason::Unsupported => 'placed by a callback, so its file type cannot be told',
        };
    }

    private static function advice(RejectionReason $reason, int $count): string
    {
        return match ($reason) {
            RejectionReason::NotOwned => 'helpers and plain classes; nothing to do',
            RejectionReason::Ineligible => $count === 1 ? 'check it' : 'check them',
            RejectionReason::Ambiguous => 'give one file type a priority',
            RejectionReason::Unsupported => 'nothing to do unless it should be discovered',
        };
    }
}
