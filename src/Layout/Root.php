<?php

namespace Tey\Mod\Layout;

use Closure;

/**
 * A root's kinds, declared inside `->root(..., fn (Root $root) => $root->kind(...))`.
 *
 * Kinds declared here live in this root unless their `in:` names another.
 * Returns to the layout chain when the closure ends.
 */
final readonly class Root
{
    /**
     * @internal created by Layout::root()
     */
    public function __construct(
        public string $name,
        private Layout $layout,
    ) {}

    /**
     * @param  list<string>|null  $except
     * @param  (Closure(Kind): mixed)|null  $using
     */
    public function kind(
        string $id,
        ?string $in = null,
        ?string $suffix = null,
        ?string $fixed = null,
        ?bool $timestamped = null,
        string|false|null $command = null,
        ?int $priority = null,
        ?bool $nested = null,
        ?string $discover = null,
        ?array $except = null,
        ?Closure $using = null,
    ): self {
        $this->layout->kind($id, $in, $suffix, $fixed, $timestamped, $command, $priority, $nested, $discover, $except, function (Kind $kind) use ($using): void {
            $kind->withinRoot($this->name);

            if ($using !== null) {
                $using($kind);
            }
        });

        return $this;
    }
}
