<?php

namespace Tey\Mod\Exceptions;

use Tey\Mod\Placement\Collision;

/**
 * A generation plan that must not be written, with every reason at once.
 *
 * @api
 */
final class GenerationRefused extends ModException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons, public readonly bool $nothingMissing = false)
    {
        parent::__construct(implode(PHP_EOL, $reasons));
    }

    /**
     * One line per existing file or class. When a file that does not exist
     * yet is held back too, a last line says "Nothing was written.".
     * `$nothingMissing`: every file of the plan already exists, so nothing new
     * was asked for (make:* exits 0 then, and so does mod).
     *
     * @param  list<Collision>  $collisions
     */
    public static function collisions(array $collisions, bool $nothingMissing = false): self
    {
        $reasons = array_map(static fn (Collision $collision): string => $collision->message(), $collisions);

        if (! $nothingMissing) {
            $reasons[] = 'Nothing was written.';
        }

        return new self($reasons, $nothingMissing);
    }

    /** @api */
    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
