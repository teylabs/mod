<?php

namespace Tey\Mod\Maintenance;

use Closure;
use RuntimeException;

/** Deterministic file replacement under an already prepared claim. @internal */
final class FileRepair
{
    /** @return array{written: list<string>, reconciled: list<string>} */
    public static function apply(Journal $journal, ?Closure $afterReplace = null): array
    {
        $plan = $journal->plan();
        $files = $plan->target->files;
        ksort($files);
        $written = $reconciled = [];
        foreach ($files as $relative => $bytes) {
            $journal->assertCurrent();
            $observed = SafeFilesystem::read($plan->root, $relative);
            if ($observed === $bytes) {
                $reconciled[] = $relative;
            } else {
                $expected = $plan->files[$relative]['current'];
                if (($observed === null ? null : hash('sha256', $observed)) !== $expected) {
                    throw new RuntimeException('Generated file changed before replacement.');
                }
                self::replace($journal, $relative, $bytes, $observed);
                $written[] = $relative;
                if ($afterReplace !== null) {
                    $afterReplace($relative);
                }
            }
            $journal->completeFile($relative);
        }

        return ['written' => $written, 'reconciled' => $reconciled];
    }

    private static function replace(Journal $journal, string $relative, string $bytes, ?string $observed): void
    {
        $plan = $journal->plan();
        $path = SafeFilesystem::path($plan->root, $relative);
        $permissions = is_file($path) ? fileperms($path) : null;
        $parent = dirname($path);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true)) {
            throw new RuntimeException('Unable to create declared file parents.');
        }
        SafeFilesystem::path($plan->root, $relative);
        $temporary = SafeFilesystem::path($plan->root, $relative.'.mod-maintenance-tmp-'.bin2hex(random_bytes(12)));
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare replacement bytes.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $count = fwrite($stream, substr($bytes, $offset));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Unable to write complete replacement bytes.');
                }
                $offset += $count;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Unable to flush replacement bytes.');
            }
            fclose($stream);
            if ($permissions !== null && ($permissions === false || ! chmod($temporary, $permissions & 0777))) {
                throw new RuntimeException('Unable to preserve generated file permissions.');
            }
            $journal->assertCurrent();
            SafeFilesystem::path($plan->root, $relative);
            if (SafeFilesystem::read($plan->root, $relative) !== $observed || ! rename($temporary, $path)) {
                throw new RuntimeException('Generated file changed immediately before replacement.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }
}
