<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Tey\Mod\Discovery\Discovery;
use Tey\Mod\Discovery\DiscoveryOptions;
use Tey\Mod\Discovery\DiscoveryRegistrar;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Tests\Feature\Discovery\Support\DiscoveryFixture;
use Tey\Mod\Tests\Feature\Discovery\Support\Sources;

function lifetimeTree(DiscoveryFixture $fx, string $tag): CompiledLayout
{
    $fx->write('app/Providers/'.$tag.'ServiceProvider.php', Sources::provider('App\\Providers', $tag.'ServiceProvider', 'fixture.'.strtolower($tag)))
        ->write('app/Console/Commands/'.$tag.'Report.php', Sources::command('App\\Console\\Commands', $tag.'Report', 'fixture:'.strtolower($tag)))
        ->write('app/Events/InvoicePaid.php', Sources::event('App\\Events', 'InvoicePaid'))
        ->write('app/Listeners/SendReceipt.php', Sources::listener('App\\Listeners', 'SendReceipt', 'handle', '\\{{ns}}\\App\\Events\\InvoicePaid'));

    return $fx->layout('ordinary');
}

/**
 * @return list<mixed>
 */
function rawListenersFor(Dispatcher $dispatcher, string $event): array
{
    return $dispatcher->getRawListeners()[$event] ?? [];
}

it('keeps two applications in one process apart', DiscoveryFixture::around(function (DiscoveryFixture $first) {
    $second = DiscoveryFixture::create();
    $other = null;

    try {
        $this->app->setBasePath($first->path());
        $one = DiscoveryRegistrar::register($this->app, lifetimeTree($first, 'Alpha'));

        $other = $this->createApplication();
        $other->setBasePath($second->path());
        $two = DiscoveryRegistrar::register($other, lifetimeTree($second, 'Beta'));

        expect($one)->not->toBe($two)
            ->and($this->app->make(Discovery::class))->toBe($one)
            ->and($other->make(Discovery::class))->toBe($two)
            ->and($one->inventory()->equals($two->inventory()))->toBeFalse()
            ->and($this->app->bound('fixture.alpha'))->toBeTrue()
            ->and($this->app->bound('fixture.beta'))->toBeFalse()
            ->and($other->bound('fixture.beta'))->toBeTrue()
            ->and($other->bound('fixture.alpha'))->toBeFalse();

        $mine = $this->app->make(Kernel::class)->all();
        $theirs = $other->make(Kernel::class)->all();

        expect(array_keys($mine))->toContain('fixture:alpha')
            ->and(array_keys($mine))->not->toContain('fixture:beta')
            ->and(array_keys($theirs))->toContain('fixture:beta')
            ->and(array_keys($theirs))->not->toContain('fixture:alpha');
    } finally {
        $other?->flush();
        $second->destroy();
    }
}));

it('does not double-register listeners on the same dispatcher', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = lifetimeTree($fx, 'Alpha');
    $this->app->setBasePath($fx->path());

    $discovery = DiscoveryRegistrar::register($this->app, $preset);
    $again = DiscoveryRegistrar::register($this->app, $preset);
    $paid = $fx->class('App\\Events\\InvoicePaid');

    expect($again)->toBe($discovery)
        ->and($discovery->registerListeners($this->app->make('events')))->toBe(0)
        ->and(rawListenersFor($this->app->make('events'), $paid))->toHaveCount(1);

    event(new $paid);

    expect(DiscoveryFixture::handled($this->app))->toHaveCount(1);
}));

it('registers listeners once on a replacement dispatcher', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = lifetimeTree($fx, 'Alpha');
    $this->app->setBasePath($fx->path());
    DiscoveryRegistrar::register($this->app, $preset);
    $paid = $fx->class('App\\Events\\InvoicePaid');

    $replacement = new Dispatcher($this->app);
    $this->app->instance('events', $replacement);
    $this->app->instance('events', $replacement);

    expect(rawListenersFor($replacement, $paid))->toBe([$fx->class('App\\Listeners\\SendReceipt').'@handle']);

    event(new $paid);

    expect(DiscoveryFixture::handled($this->app))->toHaveCount(1);
}));

it('does not register again through a fake wrapping the registered dispatcher', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = lifetimeTree($fx, 'Alpha');
    $this->app->setBasePath($fx->path());
    DiscoveryRegistrar::register($this->app, $preset);
    $real = $this->app->make('events');
    $paid = $fx->class('App\\Events\\InvoicePaid');

    Event::fake();

    expect(rawListenersFor($real, $paid))->toHaveCount(1);
    Event::assertListening($paid, $fx->class('App\\Listeners\\SendReceipt').'@handle');
}));

it('refuses a second registration with a different preset on the same application', DiscoveryFixture::around(function (DiscoveryFixture $fx) {
    $preset = lifetimeTree($fx, 'Alpha');
    $this->app->setBasePath($fx->path());
    DiscoveryRegistrar::register($this->app, $preset);

    expect(fn () => DiscoveryRegistrar::register($this->app, $preset, DiscoveryOptions::fromConfig(['file_types' => ['listener' => false]])))
        ->toThrow(ModException::class, 'already registered');
}));
