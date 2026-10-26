<?php

namespace Tey\Mod\Discovery;

use Illuminate\Contracts\Foundation\Application;
use Tey\Mod\Layout\CompiledLayout;

/**
 * The groups `mod.disabled` switches off. A group is named as mod:list prints
 * it: its dimension values joined with "/" (Billing, or Shop/Billing). A name
 * also covers the groups nested below it. Read at registration, never at scan,
 * so the discovery cache holds every group and needs no rebuild when the list
 * changes.
 *
 * @internal
 */
final readonly class DisabledGroups
{
    /** @var list<string> */
    public array $names;

    /** @param iterable<mixed> $names */
    public function __construct(iterable $names = [])
    {
        $clean = [];
        foreach ($names as $name) {
            if (is_string($name) && trim($name, " /\t") !== '') {
                $clean[] = trim($name, " /\t");
            }
        }
        $this->names = array_values(array_unique($clean));
    }

    public static function of(Application $app): self
    {
        $names = $app->make('config')->get('mod.disabled', []);

        return new self(is_string($names) ? explode(',', $names) : (is_iterable($names) ? $names : []));
    }

    public function isEmpty(): bool
    {
        return $this->names === [];
    }

    public function contains(?string $group): bool
    {
        if ($group === null || $group === '') {
            return false;
        }
        foreach ($this->names as $name) {
            if ($group === $name || str_starts_with($group, $name.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $context */
    public function containsContext(array $context, CompiledLayout $layout): bool
    {
        if ($this->names === []) {
            return false;
        }
        $values = [];
        foreach ($layout->dimensionNames() as $dimension) {
            if (isset($context[$dimension]) && $context[$dimension] !== '') {
                $values[] = $context[$dimension];
            }
        }

        return $this->contains(implode('/', $values));
    }

    /** The inventory without the disabled groups' entries; directories (migrations) stay. */
    public function filter(Inventory $inventory, CompiledLayout $layout): Inventory
    {
        if ($this->names === []) {
            return $inventory;
        }

        return new Inventory(
            array_values(array_filter($inventory->entries, fn (DiscoveredArtifact $entry): bool => $entry->type === DiscoveryType::Directory || ! $this->containsContext($entry->context, $layout))),
            $inventory->rejections,
        );
    }

    /**
     * Names that match no existing group.
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    public function unknown(array $groups): array
    {
        return array_values(array_filter($this->names, static function (string $name) use ($groups): bool {
            foreach ($groups as $group) {
                if ($group === $name || str_starts_with($group, $name.'/')) {
                    return false;
                }
            }

            return true;
        }));
    }
}
