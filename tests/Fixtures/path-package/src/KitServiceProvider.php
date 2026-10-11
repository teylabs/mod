<?php

namespace Acme\Kit;

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;

final class KitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mod::pages('acme', __DIR__.'/../resources/js/pages');
        Mod::wiring()->alias('@acme/kit', 'vendor/acme/kit/resources/js')
            ->source(__DIR__.'/../resources/js')->css('vendor/acme/kit/resources/kit.css');
    }
}
