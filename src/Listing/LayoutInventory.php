<?php

namespace Tey\Mod\Listing;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Generation\GeneratorRegistry;
use Tey\Mod\Generation\GroupFolders;
use Tey\Mod\Generation\PackageDetector;
use Tey\Mod\Generation\StubRegistry;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Scaffolds\ScaffoldRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Templates\TemplateCatalog;

/**
 * @internal Read-only data for both mod:list formats.
 *
 * @phpstan-type Entry array{kind: string, type: string, class: string, path: string, context: array<string, string>, events: list<array{event: string, method: string}>, target?: string}
 * @phpstan-type Rejected array{path: string, reason: string, detail: string, kind: ?string, class: ?string, candidates: list<string>}
 * @phpstan-type TypeRow array{id: string, command: ?string, aliases: list<string>, folder: string, source: string, stub: ?string, base: ?string, discovery: list<string>, classes: list<Entry>, relations: list<string>}
 * @phpstan-type MemberRow array{alias: string, type: string, name: ?string, stub: ?string, options: array<array-key, mixed>, folder: string}
 * @phpstan-type ScaffoldRow array{name: string, command: string, source: string, members: list<MemberRow>}
 * @phpstan-type Problem array{path: string, reason: string}
 * @phpstan-type Report array{layout: string, extends: ?string, path: ?string, token: ?string, groups: list<string>, types: list<TypeRow>, templates: array{problems: list<Problem>, notices: list<string>}, scaffolds: array{items: list<ScaffoldRow>, problems: list<array{name: string, reason: string}>}, discovery: array{enabled: bool, source: ?string, counts: array<string, int>, entries: list<Entry>, rejections: list<Rejected>, stale_cache: ?string}}
 */
final readonly class LayoutInventory
{
    public function __construct(private Application $app) {}

    /** @return Report */
    public function read(): array
    {
        $layout = $this->app->make(CompiledLayout::class);
        $config = $this->app->make('config');
        $configuredName = $config->get('mod.layout', 'laravel');
        $name = is_string($configuredName) ? $configuredName : 'layout';
        $registry = $this->app->make(LayoutRegistry::class);
        $builder = $registry->has($name) ? $registry->layout($name) : null;
        $options = DiscoveryOptions::fromConfig((array) $config->get('mod.discovery', []));
        $discovery = $options->enabled ? ($this->app->bound(Discovery::class) ? $this->app->make(Discovery::class) : new Discovery($layout, $options, $this->app->basePath())) : null;
        $inventory = $discovery?->inventory() ?? new Inventory;
        $counts = [];
        foreach (DiscoveryType::cases() as $type) {
            $counts[$type->value] = count($inventory->ofType($type));
        }
        $counts['rejected'] = count($inventory->rejections);
        $types = [];
        $commands = $this->app->make(GeneratorRegistry::class)->commands($layout, $this->app);
        foreach ($layout->kinds() as $id => $kind) {
            if ($kind->command === null) {
                continue;
            }
            $command = null;
            foreach ($commands as $candidate) {
                if ($candidate->getName() === $kind->command) {
                    $command = $candidate;
                    break;
                }
            }
            if ($command === null) {
                continue;
            }
            if ($command instanceof Command) {
                $command->setLaravel($this->app);
            }
            $selection = method_exists($command, 'stubSelection') ? $command->stubSelection() : $this->app->make(StubRegistry::class)->select(
                $id, $layout->stub($id), $this->app->basePath(), $this->app->make(PackageDetector::class),
                static fn (string $key): mixed => $config->get($key), native: true,
            );
            $definitions = [];
            foreach ($options->definitionsFor($layout) as $definition) {
                if ($definition->kindId === $id && $definition->enabled) {
                    $definitions[] = $definition->type->value;
                }
            }
            $types[] = ['id' => $id, 'command' => $kind->command, 'aliases' => $kind->aliases, 'folder' => self::folder($layout, $id), 'source' => $selection->source, 'stub' => $selection->file === null ? null : (Path::relative($this->app->basePath(), $selection->file) ?? Path::normalize($selection->file)), 'base' => $selection->choice?->base, 'discovery' => $options->enabled ? $definitions : [], 'classes' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->ofKind($id)), 'relations' => array_map(static fn ($relation): string => $relation->id, $layout->relationsFrom($id))];
        }
        usort($types, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $scaffolds = $this->app->make(ScaffoldRegistry::class);
        $recipes = $scaffolds->resolved();
        $items = [];
        foreach ($recipes as $scaffoldName => $recipe) {
            $members = [];
            foreach ($recipe->members() as $alias => $member) {
                $members[] = ['alias' => $alias, 'type' => $member->fileType, 'name' => $member->name, 'stub' => $member->stub, 'options' => $member->options, 'folder' => self::folder($layout, $member->fileType)];
            }
            $items[] = ['name' => $scaffoldName, 'command' => 'mod:'.$scaffoldName, 'source' => $scaffolds->sources()[$scaffoldName], 'members' => $members];
        }
        usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        $catalog = $this->app->make(TemplateCatalog::class);
        $problems = [];
        foreach ($catalog->skipped() as $path => $reason) {
            $problems[] = ['path' => $path, 'reason' => $reason];
        }
        $scaffoldProblems = [];
        foreach ($scaffolds->problems() as $scaffoldName => $reason) {
            $scaffoldProblems[] = ['name' => $scaffoldName, 'reason' => $reason];
        }

        return ['layout' => $name, 'extends' => $builder?->parentName(), 'path' => $builder?->toArray()['path'], 'token' => implode('/', $layout->dimensionNames()) ?: null, 'groups' => (new GroupFolders($this->app->basePath()))->groups($layout), 'types' => $types, 'templates' => ['problems' => $problems, 'notices' => $catalog->notices()], 'scaffolds' => ['items' => $items, 'problems' => $scaffoldProblems], 'discovery' => ['enabled' => $options->enabled, 'source' => $discovery?->source(), 'counts' => $counts, 'entries' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->entries), 'rejections' => array_map(static fn ($entry): array => $entry->toArray(), $inventory->rejections), 'stale_cache' => $discovery?->staleCacheReason()]];
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
