<?php

namespace Tey\Mod\Rename;

/** @internal Named registration lets PHP analysis replace the declaration seam. */
final class Contributors
{
    /** @var array<string, Contributor> */
    private array $items = [];

    public function __construct()
    {
        $this->items['declarations'] = new Declarations;
    }

    public function set(string $name, Contributor $contributor): void
    {
        $this->items[$name] = $contributor;
    }

    /** @return list<Contribution> */
    public function collect(Inputs $inputs): array
    {
        $items = $this->items;
        ksort($items);

        return array_values(array_map(static fn (Contributor $item): Contribution => $item->contribute($inputs), $items));
    }
}
