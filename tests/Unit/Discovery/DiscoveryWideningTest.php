<?php

use Tey\Mod\Discovery\DiscoveryDefinition;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Discovery\PresetFingerprint;
use Tey\Mod\Exceptions\InvalidDiscoveryConfig;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Exceptions\InvalidPreset;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Layout\LayoutRegistry;
use Tey\Mod\Layout\Root;
use Tey\Mod\Placement\PlacementContext;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Reverse\ReverseMapper;
use Tey\Mod\Reverse\ReverseOutcome;

/*
 * Discover-anywhere, directory discovery and subscribers at the
 * preset level: the pure parts (recognition, definitions, fingerprints).
 */
function anywhereLayout(): Preset
{
    return (new Layout('anywhere'))
        ->root('src', 'Src\\', 'src', fn (Root $r) => $r
            ->kind('provider', in: '{group+}/Providers', suffix: 'Provider', discoverAnywhere: true, except: ['Tests', 'Database/Migrations'])
            ->kind('subscriber', in: '{group+}/Listeners', discoverAnywhere: true)
            ->kind('model', in: '{group+}/Models')
            ->kind('migration', in: '{group+}/Database/Migrations', timestamped: true))
        ->compile();
}

it('offers files anywhere below the dimension folders for discovery, except the excluded folders', function () {
    $preset = anywhereLayout();
    $rule = $preset->rule('provider');
    expect($rule)->toBeInstanceOf(TemplateRule::class);
    assert($rule instanceof TemplateRule);
    $kind = $preset->kind('provider');

    $deep = $rule->recogniseAnywhere($kind, 'src/Billing/Support/Deep/BillingProvider.php');
    expect($deep?->fqcn())->toBe('Src\Billing\Support\Deep\BillingProvider')
        ->and($deep?->context->toArray())->toBe(['group' => 'Billing'])
        ->and($deep?->nested)->toBe(['Support', 'Deep'])
        ->and($deep?->name)->toBe('Billing');

    expect($rule->recogniseAnywhere($kind, 'src/Billing/Tests/FakeProvider.php'))->toBeNull()
        ->and($rule->recogniseAnywhere($kind, 'src/Billing/Database/Migrations/OddProvider.php'))->toBeNull()
        ->and($rule->recogniseAnywhere($kind, 'src/Billing/Database/Seeders/SeedProvider.php'))->not->toBeNull()
        ->and($rule->recogniseAnywhere($kind, 'src/Billing/Helper.php'))->toBeNull('suffix policy still decides')
        ->and($rule->recogniseAnywhere($kind, 'app/Billing/XProvider.php'))->toBeNull('other root')
        ->and($rule->recogniseAnywhere($kind, 'src/XProvider.php'))->toBeNull('the required dimension needs a folder');
});

it('keeps reverse mapping template-exact for anywhere kinds', function () {
    $mapper = new ReverseMapper(anywhereLayout());

    expect($mapper->fromPath('src/Billing/Support/Deep/BillingProvider.php')->outcome)->toBe(ReverseOutcome::NotOwned)
        ->and($mapper->fromPath('src/Billing/Providers/BillingProvider.php')->outcome)->toBe(ReverseOutcome::Matched);
});

it('recognises the directories a file kind template binds', function () {
    $rule = anywhereLayout()->rule('migration');
    assert($rule instanceof TemplateRule);

    expect($rule->recogniseDirectory('src/Billing/Database/Migrations')?->toArray())->toBe(['group' => 'Billing'])
        ->and($rule->recogniseDirectory('src/Billing/Invoicing/Database/Migrations')?->toArray())->toBe(['group' => 'Billing/Invoicing'])
        ->and($rule->recogniseDirectory('src/Billing/Database'))->toBeNull()
        ->and($rule->recogniseDirectory('src/Billing/Database/Migrations/Archive'))->toBeNull()
        ->and($rule->recogniseDirectory('src'))->toBeNull();
});

it('declares subscriber and directory definitions', function () {
    $preset = anywhereLayout();

    expect(array_map(fn (DiscoveryDefinition $d) => $d->identity(), (new DiscoveryOptions)->definitionsFor($preset)))
        ->toBe(['migration:directory:on', 'provider:provider:on', 'subscriber:subscriber:on']);

    $options = DiscoveryOptions::fromConfig(['kinds' => ['migration' => 'directory', 'subscriber' => false]]);
    expect(array_map(fn (DiscoveryDefinition $d) => $d->identity(), $options->definitionsFor($preset)))
        ->toBe(['migration:directory:on', 'provider:provider:on', 'subscriber:subscriber:off']);

    expect(DiscoveryDefinition::directories('migration')->type)->toBe(DiscoveryType::Directory)
        ->and(DiscoveryDefinition::subscribers()->kindId)->toBe('subscriber');
});

it('refuses directory discovery of a class kind and class discovery of a file kind', function () {
    $preset = anywhereLayout();

    expect(fn () => DiscoveryOptions::fromConfig(['kinds' => ['model' => 'directory']])->definitionsFor($preset))
        ->toThrow(InvalidDiscoveryConfig::class, 'only file kinds can be discovered as directories')
        ->and(fn () => DiscoveryOptions::fromConfig(['kinds' => ['migration' => 'provider']])->definitionsFor($preset))
        ->toThrow(InvalidDiscoveryConfig::class, 'only class kinds can be discovered as provider');
});

it('fingerprints nested, anywhere and multi-segment rules distinctly', function () {
    $plain = (new Layout('a'))->root('src', 'Src\\', 'src')->kind('provider', in: '{group}/Providers')->compile();
    $nested = (new Layout('b'))->root('src', 'Src\\', 'src')->kind('provider', in: '{group}/Providers', nested: true)->compile();
    $anywhere = (new Layout('c'))->root('src', 'Src\\', 'src')->kind('provider', in: '{group}/Providers', discoverAnywhere: true, except: ['Tests'])->compile();
    $multi = (new Layout('d'))->root('src', 'Src\\', 'src')->kind('provider', in: '{group+}/Providers')->compile();

    $prints = array_map(PresetFingerprint::of(...), [$plain, $nested, $anywhere, $multi]);

    expect(count(array_unique($prints)))->toBe(4);
});

it('refuses except without anywhere and anywhere on a callback kind', function () {
    expect(fn () => (new Layout('x'))->root('src', 'Src\\', 'src')->kind('provider', in: 'Providers', except: ['Tests'])->compile())
        ->not->toThrow(InvalidLayout::class, 'except implies anywhere through the builder');

    $callback = ['roots' => ['src' => ['namespace' => 'Src\\', 'path' => 'src']], 'kinds' => ['provider' => [
        'shape' => 'class', 'root' => 'src', 'discover' => 'anywhere',
        'place' => fn (string $name, PlacementContext $context): string => 'Providers',
    ]]];

    expect(fn () => Preset::fromArray($callback))->toThrow(InvalidPreset::class, 'declarative placement');

    $exceptOnly = ['roots' => ['src' => ['namespace' => 'Src\\', 'path' => 'src']], 'kinds' => ['provider' => [
        'shape' => 'class', 'root' => 'src', 'segments' => ['Providers'], 'except' => ['Tests'],
    ]]];

    expect(fn () => Preset::fromArray($exceptOnly))->toThrow(InvalidPreset::class, 'except needs discover');
});

it('skips the excluded folders below the dimension folders of every built-in shape', function (string $layout, string $found, array $context, string $excluded, string $outside) {
    $registry = new LayoutRegistry;
    $registry->layout($layout)->kind('provider', discoverAnywhere: true, except: ['Tests']);
    $preset = $registry->compile($layout);
    $rule = $preset->rule('provider');
    assert($rule instanceof TemplateRule);
    $kind = $preset->kind('provider');

    $match = $rule->recogniseAnywhere($kind, $found);

    expect($match?->context->toArray())->toBe($context)
        ->and($match?->nested)->toBe(['Support'])
        ->and($rule->recogniseAnywhere($kind, $excluded))->toBeNull()
        ->and($rule->recogniseAnywhere($kind, $outside))->toBeNull('outside the fixed folders');
})->with([
    'modules' => ['modules', 'app/Modules/Billing/Support/BillingServiceProvider.php', ['module' => 'Billing'], 'app/Modules/Billing/Tests/FakeServiceProvider.php', 'app/Http/FakeServiceProvider.php'],
    'features' => ['features', 'app/Features/Billing/Support/BillingServiceProvider.php', ['feature' => 'Billing'], 'app/Features/Billing/Tests/FakeServiceProvider.php', 'app/Http/FakeServiceProvider.php'],
    'type-first' => ['type-first', 'app/Providers/Billing/Support/BillingServiceProvider.php', ['feature' => 'Billing'], 'app/Providers/Billing/Tests/FakeServiceProvider.php', 'app/Http/Billing/FakeServiceProvider.php'],
    'ddd' => ['ddd', 'src/Domain/Billing/Support/BillingProvider.php', ['domain' => 'Billing'], 'src/Domain/Billing/Tests/FakeProvider.php', 'app/Modules/Billing/FakeProvider.php'],
    'slices' => ['slices', 'app/Billing/Support/BillingServiceProvider.php', ['feature' => 'Billing'], 'app/Billing/Tests/FakeServiceProvider.php', 'app/BillingServiceProvider.php'],
]);
