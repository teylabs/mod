<?php

namespace Tey\Mod\Generation;

use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Placement\Collision;

/**
 * A generation plan that must not be written, with every reason at once.
 */
final class GenerationRefused extends ModException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(PHP_EOL, $reasons));
    }

    /**
     * @param  list<Collision>  $collisions
     */
    public static function collisions(array $collisions): self
    {
        return new self(array_map(
            static fn (Collision $collision): string => 'Refusing to write: '.$collision->describe().'.',
            $collisions,
        ));
    }

    public static function because(string $reason): self
    {
        return new self([$reason]);
    }
}
