<?php

namespace Tey\Mod\Listing;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Support\Path;

/**
 * @internal Read-only data for both mod:list formats.
 *
 * @phpstan-type Entry array{kind: string, type: string, class: string, path: string, context: array<string, string>, events: list<array{event: string, method: string}>, target?: string}
 * @phpstan-type Rejected array{path: string, reason: string, detail: string, kind: ?string, class: ?string, candidates: list<string>}
 * @phpstan-type TypeRow array{id: string, command: ?string, aliases: list<string>, folder: string, source: string, stub: ?string, base: ?string, discovery: list<string>, classes: list<Entry>, relations: list<string>}
 * @phpstan-type MemberRow array{alias: string, type: string, name: ?string, stub: ?string, options: array<array-key, mixed>, folder: string}
 * @phpstan-type ScaffoldRow array{name: string, command: string, source: string, members: list<MemberRow>, uses?: ?string, children?: list<string>}
 * @phpstan-type Problem array{path: string, reason: string}
 * @phpstan-type Report array{layout: string, extends: ?string, path: ?string, token: ?string, groups: list<string>, types: list<TypeRow>, templates: array{problems: list<Problem>, notices: list<string>}, scaffolds: array{items: list<ScaffoldRow>, problems: list<array{name: string, reason: string}>}, discovery: array{enabled: bool, source: ?string, counts: array<string, int>, entries: list<Entry>, rejections: list<Rejected>, stale_cache: ?string}}
 * @phpstan-type PartialReport array{layout?: string, extends?: ?string, path?: ?string, token?: ?string, groups?: list<string>, types?: list<TypeRow>, templates?: array{problems: list<Problem>, notices: list<string>}, scaffolds?: array{items: list<ScaffoldRow>, problems: list<array{name: string, reason: string}>}, discovery?: array{enabled: bool, source: ?string, counts: array<string, int>, entries: list<Entry>, rejections: list<Rejected>, stale_cache: ?string}}
 */
final readonly class LayoutInventory
{
    public function __construct(private Application $app) {}

    /** @return Report */
    public function read(): array
    {
        return (new InventorySectionRegistry)->read($this->app);
    }

    public static function folder(CompiledLayout $layout, string $id): string
    {
        $rule = $layout->rule($id);
        if (! $rule instanceof TemplateRule) {
            return 'callback';
        }
        $parts = array_map(static fn ($segment): string => $segment->literal ?? '{'.$segment->dimension.($segment->multi ? '+' : '').'}', $rule->segments());

        return Path::join($rule->root()->path, ...$parts);
    }
}
