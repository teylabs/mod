<?php

use Illuminate\Foundation\Application;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\Inventory;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Listing\InventorySection;
use Tey\Mod\Listing\InventorySectionRegistry;
use Tey\Mod\Support\Path;
use Tey\Mod\Tests\Feature\Generation\Support\Workspace;
use Tey\Mod\Tests\Support\JsonSchema;

it('pins inventory bytes with the intended 0.3 HTTP placements and additive frontend section', function (string $layout) {
    Workspace::run(null, function (Workspace $w) use ($layout) {
        config()->set('mod.layout', $layout);
        $output = $w->artisan('mod:list', ['--json' => true])->assertSuccessful()->normalisedOutput();
        // Package templates have absolute source paths; normalize only the checkout root.
        $output = str_replace(Path::normalize(dirname(__DIR__, 3)), '<package>', $output);
        expect($output)->toEqualText(file_get_contents(__DIR__.'/../../Fixtures/list/'.$layout.'.json'));
    });
})->with(['laravel', 'modules', 'ddd', 'features', 'slices', 'type-first']);

it('registers a schema fragment for every existing section and pins nested keys', function () {
    Workspace::run(null, function (Workspace $w) {
        config()->set('mod.layout', 'modules');
        $registry = new InventorySectionRegistry;
        $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        $data = json_decode($w->artisan('mod:list', ['--json' => true])->assertSuccessful()->output, true, flags: JSON_THROW_ON_ERROR);
        expect($registry->schema())->toBe($schema)
            ->and(JsonSchema::errors($data, $schema))->toBe([]);
        $data['future'] = ['enabled' => true];
        $data['types'][0]['future'] = true;
        expect(JsonSchema::errors($data, $schema))->toBe([]);
        unset($data['types'][0]['aliases']);
        expect(JsonSchema::errors($data, $schema))->toContain('$.types[0].aliases is required');
    });
});

it('adds a section with one registration and refuses unpinned or replaced keys', function (string $key, bool $pinned, bool $accepted) {
    Workspace::run(null, function () use ($key, $pinned, $accepted) {
        $section = new class($key, $pinned) implements InventorySection
        {
            public function __construct(private string $key, private bool $pinned) {}

            /** @return array{layout?: string, future?: string} */
            public function read(Application $app, CompiledLayout $layout, DiscoveryOptions $options, Inventory $inventory, ?Discovery $discovery): array
            {
                return $this->key === 'layout' ? ['layout' => 'replacement'] : ['future' => 'new'];
            }

            /** @return array{type: string, required: list<string>, properties: array<string, mixed>} */
            public function schema(): array
            {
                return ['type' => 'object', 'required' => $this->pinned ? [$this->key] : [], 'properties' => $this->pinned ? [$this->key => ['type' => 'string']] : []];
            }
        };
        $registry = new InventorySectionRegistry([$section]);
        if ($accepted) {
            expect($registry->read(app()))->toHaveKey('future', 'new')
                ->and($registry->schema()['required'])->toContain('future');
        } else {
            expect(fn () => $registry->read(app()))->toThrow(LogicException::class);
        }
    });
})->with([['future', true, true], ['future', false, false], ['layout', true, false]]);
