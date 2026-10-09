<?php

use Tey\Mod\Discovery\RejectionReason;
use Tey\Mod\Facades\Mod;
use Tey\Mod\Tests\Feature\Acceptance\Support\AcceptanceApp;
use Tey\Mod\Tests\Feature\Acceptance\Support\LayoutUnderTest;

/*
 * Acceptance, a subscriber in a Listeners folder: discovered the way
 * Laravel's own event discovery treats it. Typed handle*() methods register
 * as listeners and subscribe() is never called; a subscriber with no typed
 * handler is skipped. Subscribers register through a subscriber kind.
 */

it('treats a subscriber in Listeners as Laravel event discovery does', function () {
    AcceptanceApp::run('modules', function (AcceptanceApp $app) {
        $t = $app->tag;
        $ns = "App\\Modules\\Billing{$t}";
        $event = "{$ns}\\Events\\Invoice{$t}Paid";
        $app->handWrite("app/Modules/Billing{$t}/Events/Invoice{$t}Paid.php", "{$ns}\\Events", "class Invoice{$t}Paid {}");
        $app->handWrite("app/Modules/Billing{$t}/Listeners/Typed{$t}Subscriber.php", "{$ns}\\Listeners", <<<PHP
            class Typed{$t}Subscriber
            {
                public function handlePaid(\\{$event} \$event): void {}

                public function subscribe(\$events): array
                {
                    return [\\{$event}::class => 'handlePaid'];
                }
            }
            PHP);
        $plain = $app->handWrite("app/Modules/Billing{$t}/Listeners/Plain{$t}Subscriber.php", "{$ns}\\Listeners", <<<PHP
            class Plain{$t}Subscriber
            {
                public function onPaid(\$event): void {}

                public function subscribe(\$events): void
                {
                    \$events->listen(\\{$event}::class, [self::class, 'onPaid']);
                }
            }
            PHP);

        $app->boot();

        expect($app->app()->make('events')->getRawListeners()[$event] ?? [])->toBe(["{$ns}\\Listeners\\Typed{$t}Subscriber@handlePaid"])
            ->and($app->discovery()->inventory()->rejection($plain)?->reason)->toBe(RejectionReason::Ineligible);
    });
});

it('subscribes the classes of a subscriber kind', function () {
    AcceptanceApp::run(new LayoutUnderTest('modules', fn () => Mod::layout('modules')
        ->generates('subscriber', in: 'Modules/{module}/Subscribers')), function (AcceptanceApp $app) {
            $t = $app->tag;
            $ns = "App\\Modules\\Billing{$t}";
            $event = "{$ns}\\Events\\Invoice{$t}Paid";
            $app->handWrite("app/Modules/Billing{$t}/Events/Invoice{$t}Paid.php", "{$ns}\\Events", "class Invoice{$t}Paid {}");
            $app->handWrite("app/Modules/Billing{$t}/Subscribers/Billing{$t}Subscriber.php", "{$ns}\\Subscribers", <<<PHP
                class Billing{$t}Subscriber
                {
                    public function onPaid(\$event): void {}

                    public function subscribe(\$events): void
                    {
                        \$events->listen(\\{$event}::class, [self::class, 'onPaid']);
                    }
                }
                PHP);

            $app->boot();

            expect($app->app()->make('events')->getRawListeners()[$event] ?? [])->toBe([["{$ns}\\Subscribers\\Billing{$t}Subscriber", 'onPaid']]);
        });
});
