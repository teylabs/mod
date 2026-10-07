<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Support\Facades\Event;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Preset\Preset;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;
use Tey\Mod\Tests\Fixtures\Layouts;

/*
 * Laravel discovers app/Listeners itself (and may have cached that); mod must
 * not register those listeners a second time, whichever side runs first, and
 * must not subscribe a subscriber the application already subscribed.
 */

function overlapTree(DiscoveryFixture $fx): Preset
{
    $events = '\\{{ns}}\\App\\Events\\';
    $fx
        ->write('app/Events/InvoicePaid.php', Sources::event('App\\Events', 'InvoicePaid'))
        ->write('app/Listeners/SendReceipt.php', Sources::listener('App\\Listeners', 'SendReceipt', 'handle', $events.'InvoicePaid'))
        ->write('app/Subscribers/InvoiceSubscriber.php', <<<PHP
        <?php

        namespace {{ns}}\\App\\Subscribers;

        class InvoiceSubscriber
        {
            public function subscribe(\\Illuminate\\Contracts\\Events\\Dispatcher \$events): void
            {
                \$events->listen({$events}InvoicePaid::class, [self::class, 'onPaid']);
            }

            public function onPaid(object \$event): void {}
        }
        PHP);

    $definition = Layouts::definition('ordinary');
    $definition['kinds']['subscriber'] = ['shape' => 'class', 'name' => 'as-given', 'command' => 'mod:subscriber', 'root' => 'app', 'segments' => ['Subscribers']];

    return $fx->preset($definition);
}

/**
 * Laravel's own listener discovery for the fixture's app/Listeners, registered the way its provider does.
 */
function laravelDiscovers(DiscoveryFixture $fx): void
{
    EventServiceProvider::setEventDiscoveryPaths([$fx->path('app/Listeners')]);
    DiscoverEvents::guessClassNamesUsing(static fn (SplFileInfo $file): string => $fx->class('App\\Listeners\\'.$file->getBasename('.php')));
    app()->register(new EventServiceProvider(app()));
}

function resetLaravelDiscovery(): void
{
    EventServiceProvider::setEventDiscoveryPaths([]);
    (new ReflectionProperty(DiscoverEvents::class, 'guessClassNamesUsingCallback'))->setValue(null, null);
}

/** @return array<int, mixed> */
function overlapRawListeners(DiscoveryFixture $fx): array
{
    /** @var Illuminate\Events\Dispatcher $events */
    $events = app('events');

    return $events->getRawListeners()[$fx->class('App\\Events\\InvoicePaid')] ?? [];
}

/**
 * The plain listener bindings ("Class@method") for InvoicePaid, without the subscriber's.
 *
 * @return list<string>
 */
function overlapListenerStrings(DiscoveryFixture $fx): array
{
    return array_values(array_filter(overlapRawListeners($fx), 'is_string'));
}

afterEach(fn () => resetLaravelDiscovery());

it('does not register a listener Laravel discovered first', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = overlapTree($fx);
    $this->app->setBasePath($fx->path());
    laravelDiscovers($fx);

    expect(overlapListenerStrings($fx))->toBe([$fx->class('App\\Listeners\\SendReceipt').'@handle']);

    DiscoveryRegistrar::register($this->app, $preset);

    expect(overlapListenerStrings($fx))->toBe([$fx->class('App\\Listeners\\SendReceipt').'@handle']);
}));

it('leaves Laravel-discovered paths to Laravel when mod registers first', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = overlapTree($fx);
    $this->app->setBasePath($fx->path());
    EventServiceProvider::setEventDiscoveryPaths([$fx->path('app/Listeners')]);

    DiscoveryRegistrar::register($this->app, $preset);
    expect(overlapListenerStrings($fx))->toBe([]);

    laravelDiscovers($fx);

    expect(overlapListenerStrings($fx))->toBe([$fx->class('App\\Listeners\\SendReceipt').'@handle']);
}));

it('does not duplicate listeners that Laravel registered from its events cache', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = overlapTree($fx);
    $this->app->setBasePath($fx->path());
    $cache = $this->app->getCachedEventsPath();
    @mkdir(dirname($cache), 0777, true);
    file_put_contents($cache, '<?php return '.var_export([EventServiceProvider::class => [$fx->class('App\\Events\\InvoicePaid') => [$fx->class('App\\Listeners\\SendReceipt').'@handle']]], true).';');
    expect($this->app->eventsAreCached())->toBeTrue();
    $this->app->register(new EventServiceProvider($this->app));

    DiscoveryRegistrar::register($this->app, $preset);

    expect(overlapListenerStrings($fx))->toBe([$fx->class('App\\Listeners\\SendReceipt').'@handle']);
}));

it('does not subscribe a subscriber the application already subscribed', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = overlapTree($fx);
    $this->app->setBasePath($fx->path());
    Event::subscribe($fx->class('App\\Subscribers\\InvoiceSubscriber'));

    DiscoveryRegistrar::register($this->app, $preset);

    $subscriberBindings = array_values(array_filter(overlapRawListeners($fx), fn ($l) => is_array($l) && $l[0] === $fx->class('App\\Subscribers\\InvoiceSubscriber')));
    expect($subscriberBindings)->toHaveCount(1)
        ->and(overlapRawListeners($fx))->toContain($fx->class('App\\Listeners\\SendReceipt').'@handle');
}));

it('still registers everything once in an application without its own discovery', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = overlapTree($fx);
    $this->app->setBasePath($fx->path());

    $discovery = DiscoveryRegistrar::register($this->app, $preset);
    /** @var Dispatcher $events */
    $events = $this->app->make('events');

    expect(overlapRawListeners($fx))->toHaveCount(2)
        ->and($discovery->registerListeners($events, DiscoveryRegistrar::applicationListenerPaths($this->app)))->toBe(0);
}));
