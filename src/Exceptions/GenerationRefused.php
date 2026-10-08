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
     * @param  list<Collision>  $collisions
     */
    public static function collisions(array $collisions, bool $duplicatePrimary = false): self
    {
        return new self(array_map(
            static fn (Collision $collision): string => 'Refusing to write: '.$collision->describe().'.',
            $collisions,
        ), $duplicatePrimary);
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
