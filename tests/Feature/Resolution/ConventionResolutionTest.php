<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Tey\Mod\Discovery\DiscoveryType;
use Tey\Mod\Relation\RelationPolicy;
use Tey\Mod\Resolution\ModelConventions;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * Model::factory() and the Gate's policy for models placed by every built-in
 * layout, with no newFactory() method and no package base class: through a
 * fresh application, without the discovery cache, with it, and after
 * optimize:clear.
 */
beforeEach(fn () => Factory::flushState());
afterEach(fn () => Factory::flushState());

/**
 * The model, its factory and (when the layout relates them) its policy, generated
 * with mod:* and the model's newFactory() method deleted.
 *
 * @return array{model: class-string, factory: class-string, policy: class-string|null}
 */
function generateModelWithRelations(AcceptanceApp $app, string $name): array
{
    $in = $app->preset->dimensionNames() === [] ? [] : ['--in' => 'Billing'];
    $model = place($app->preset, 'model', $name, $in['--in'] ?? '');
    $factory = place($app->preset, 'factory', $name.'Factory', $in['--in'] ?? '');
    $policyRelation = null;

    foreach ($app->preset->relationsFrom('model') as $relation) {
        if ($relation->toKind === 'policy') {
            $policyRelation = $relation;
        }
    }

    // --policy generates the policy where the relation generates it; a reference relation only names it.
    $generates = $policyRelation?->policy === RelationPolicy::Generate;
    $app->artisan('mod:model', ['name' => $name, '--factory' => true, '--policy' => $generates, ...$in])->assertSuccessful();

    $source = $app->read((string) $model->path());
    $app->write((string) $model->path(), (string) preg_replace('/\n\n    protected static function newFactory\(\).*?\n    }\n/s', "\n", $source));
    expect($app->read((string) $model->path()))->not->toContain('newFactory');

    $policy = null;

    if ($policyRelation !== null) {
        $policy = place($app->preset, 'policy', $name.'Policy', $in['--in'] ?? '');

        if (! $generates) {
            $app->artisan('mod:policy', ['name' => $name.'Policy', '--model' => $name, ...$in])->assertSuccessful();
        }
    }

    return ['model' => (string) $model->fqcn(), 'factory' => (string) $factory->fqcn(), 'policy' => $policy?->fqcn()];
}

/**
 * The model's factory, through Model::factory() as an application calls it.
 *
 * @return Factory<Model>
 */
function modelFactory(string $model): Factory
{
    $factory = call_user_func([$model, 'factory']);

    expect($factory)->toBeInstanceOf(Factory::class);

    return $factory instanceof Factory ? $factory : throw new RuntimeException("{$model}::factory() returned no factory.");
}

/**
 * @param  array{model: class-string, factory: class-string, policy: class-string|null}  $classes
 */
function expectResolution(array $classes): void
{
    $model = $classes['model'];

    expect(modelFactory($model))->toBeInstanceOf($classes['factory'])
        ->and(modelFactory($model)->make())->toBeInstanceOf($model);

    if ($classes['policy'] !== null) {
        // Registered explicitly (Laravel's own guesser happens to find a Policies folder beside Models too).
        expect(Gate::policies())->toHaveKey($model, $classes['policy'])
            ->and(Gate::getPolicyFor($model))->toBeInstanceOf($classes['policy']);
    }
}

it('resolves factories and policies by convention on every built-in layout', function (string $layout) {
    AcceptanceApp::run($layout, function (AcceptanceApp $app) {
        $app->boot();
        $classes = generateModelWithRelations($app, "Invoice{$app->tag}");

        // Every built-in relates its model to a policy.
        expect($classes['policy'])->not->toBeNull();

        // No cache: a cold scan pairs the model.
        $app->boot();
        expect($app->discovery()->source())->toBe('scan')
            ->and($app->discovery()->inventory()->pairs(DiscoveryType::Factory))->toHaveKey($classes['model']);
        expectResolution($classes);

        // The discovery cache holds the pairs.
        $app->artisan('mod:discovery-cache')->assertSuccessful();
        $app->boot();
        expect($app->discovery()->source())->toBe('cache')
            ->and($app->discovery()->inventory()->pairs(DiscoveryType::Factory))->toBe([$classes['model'] => $classes['factory']])
            ->and($app->discovery()->inventory()->pairs(DiscoveryType::Policy))->toBe($classes['policy'] === null ? [] : [$classes['model'] => $classes['policy']]);
        expectResolution($classes);

        // A model added after the cache was built: the reverse mapper answers for it.
        $late = generateModelWithRelations($app, "Receipt{$app->tag}");
        $app->boot();
        expect($app->discovery()->source())->toBe('cache')
            ->and($app->discovery()->inventory()->pairs(DiscoveryType::Factory))->not->toHaveKey($late['model'])
            ->and(modelFactory($late['model']))->toBeInstanceOf($late['factory']);

        // optimize:clear removes the cache; resolution still works.
        $app->artisan('optimize:clear')->assertSuccessful();
        $app->boot();
        expect($app->discovery()->source())->toBe('scan');
        expectResolution($classes);
        expectResolution($late);
    });
})->with(['laravel', 'features', 'slices', 'type-first', 'modules']);

it('registers nothing when factories and policies are turned off', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $app->boot();
        $classes = generateModelWithRelations($app, "Invoice{$app->tag}");
        Factory::flushState();

        $app->boot(['factories' => false, 'policies' => false]);

        expect(ModelConventions::current())->toBeNull()
            ->and($app->discovery()->inventory()->ofType(DiscoveryType::Factory))->toBe([])
            ->and($app->discovery()->inventory()->ofType(DiscoveryType::Policy))->toBe([])
            ->and(Gate::policies())->not->toHaveKey($classes['model'])
            // Laravel's own naming misses a factory placed in a module.
            ->and(fn () => call_user_func([$classes['model'], 'factory']))->toThrow(Error::class, 'Database\\Factories\\');
    });
});

it('turns factory and policy lookup off with discovery, which they read', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $app->boot();
        $classes = generateModelWithRelations($app, "Invoice{$app->tag}");
        Factory::flushState();

        $app->boot(['enabled' => false]);

        expect(ModelConventions::current())->toBeNull()
            ->and(Gate::policies())->not->toHaveKey($classes['model'])
            ->and(fn () => call_user_func([$classes['model'], 'factory']))->toThrow(Error::class, 'Database\\Factories\\');
    });
});

it('leaves a policy the application registered itself in place', function () {
    $own = new class {};
    // The application's own Gate::policy() call, in its AppServiceProvider::boot().
    $hook = new class
    {
        /** @var array{string, string}|null model class, policy class */
        public ?array $policy = null;
    };
    $layout = new LayoutUnderTest('modules', function () use ($hook): void {
        if ($hook->policy !== null) {
            Gate::policy(...$hook->policy);
        }
    });

    AcceptanceApp::run($layout, function (AcceptanceApp $app) use ($own, $hook) {
        $app->boot();
        $classes = generateModelWithRelations($app, "Invoice{$app->tag}");
        $hook->policy = [$classes['model'], $own::class];

        $app->boot();

        expect($app->discovery()->inventory()->pairs(DiscoveryType::Policy))->toBe([$classes['model'] => $classes['policy']])
            ->and(Gate::getPolicyFor($classes['model']))->toBeInstanceOf($own::class);
    });
});

it('keeps a factory resolver the application registers after mod', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $app->boot();
        $classes = generateModelWithRelations($app, "Invoice{$app->tag}");

        $app->boot();
        Factory::guessFactoryNamesUsing(fn (string $model): string => $classes['factory']);

        expect(ModelConventions::of(ModelConventions::current()))->toBeNull()
            ->and(modelFactory($classes['model']))->toBeInstanceOf($classes['factory']);
    });
});
