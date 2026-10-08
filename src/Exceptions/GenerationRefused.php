<?php

namespace Tey\Mod\Exceptions;

use Tey\Mod\Placement\Collision;

/**
 * A generation plan that must not be written, with every reason at once.
 */
final class GenerationRefused extends ModException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons, public readonly bool $duplicatePrimary = false)
    {
        parent::__construct(implode(PHP_EOL, $reasons));
    }

    /**
     * One line per existing file or class. A plan refused for anything but its
     * own file already existing ends with "Nothing was written.", since none of
     * its files are.
     *
     * @param  list<Collision>  $collisions
     */
    public static function collisions(array $collisions, bool $duplicatePrimary = false): self
    {
        $reasons = array_map(static fn (Collision $collision): string => $collision->message(), $collisions);

        if (! $duplicatePrimary) {
            $reasons[] = 'Nothing was written.';
        }

        return new self($reasons, $duplicatePrimary);
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
