<?php

namespace Tey\Mod\Listing;

use Illuminate\Foundation\Application;
use LogicException;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;

/** @internal Add a topic and its schema in one line in sections().
 * @phpstan-import-type SchemaFragment from InventorySection
 * @phpstan-import-type Report from LayoutInventory
 */
final class InventorySectionRegistry
{
    /** @param list<InventorySection> $additionalSections */
    public function __construct(private readonly array $additionalSections = []) {}

    /** @return list<InventorySection> */
    public function sections(): array
    {
        return [new LayoutSection, new TypesSection, new TemplatesSection, new ScaffoldsSection, new DiscoverySection, new FrontendSection, new WiringSection, ...$this->additionalSections];
    }

    /** @return Report */
    public function read(Application $app): array
    {
        $layout = $app->make(CompiledLayout::class);
        $options = DiscoveryOptions::fromConfig((array) $app->make('config')->get('mod.discovery', []));
        $discovery = $options->enabled ? ($app->bound(Discovery::class) ? $app->make(Discovery::class) : new Discovery($layout, $options, $app->basePath())) : null;
        $inventory = $discovery?->inventory() ?? new Inventory;
        $report = ['layout' => '', 'extends' => null, 'path' => null, 'token' => null, 'groups' => [], 'types' => [], 'templates' => ['problems' => [], 'notices' => []], 'scaffolds' => ['items' => [], 'problems' => []], 'discovery' => ['enabled' => false, 'source' => null, 'counts' => [], 'entries' => [], 'rejections' => [], 'stale_cache' => null], 'frontend' => ['pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null, 'view_namespace' => null], 'wiring' => ['inertia' => false, 'vite_alias' => false, 'tailwind' => false]];
        $keys = [];
        foreach ($this->sections() as $section) {
            $data = $section->read($app, $layout, $options, $inventory, $discovery);
            $fragment = $section->schema();
            if (array_keys($data) !== $fragment['required'] || array_keys($data) !== array_keys($fragment['properties'])) {
                throw new LogicException($section::class.' must register a schema fragment for every inventory key.');
            }
            if (array_intersect($keys, array_keys($data)) !== []) {
                throw new LogicException($section::class.' replaces an existing inventory section. Add new keys instead.');
            }
            $keys = [...$keys, ...array_keys($data)];
            $report = [...$report, ...$data];
        }

        return $report;
    }

    /** @return SchemaFragment */
    public function schema(): array
    {
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'required' => [], 'properties' => []];
        foreach ($this->sections() as $section) {
            $fragment = $section->schema();
            $schema['required'] = [...$schema['required'], ...$fragment['required']];
            $schema['properties'] = [...$schema['properties'], ...$fragment['properties']];
        }

        return $schema;
    }

    /** @return SchemaFragment */
    public static function fragment(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/Schemas/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['type'] ?? null) !== 'object' || ! is_array($data['required'] ?? null) || ! is_array($data['properties'] ?? null)) {
            throw new LogicException('Inventory schema ['.$name.'] must describe an object with required keys and properties.');
        }
        $required = [];
        foreach ($data['required'] as $key) {
            if (! is_string($key)) {
                throw new LogicException('Inventory schema ['.$name.'] has a non-string required key.');
            }
            $required[] = $key;
        }
        $properties = [];
        foreach ($data['properties'] as $key => $schema) {
            if (! is_string($key) || ! is_array($schema)) {
                throw new LogicException('Inventory schema ['.$name.'] has an invalid property.');
            }
            $properties[$key] = $schema;
        }

        return ['type' => 'object', 'required' => $required, 'properties' => $properties];
    }
}
