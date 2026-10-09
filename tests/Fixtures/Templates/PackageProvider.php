<?php

namespace Tey\Mod\Tests\Fixtures\Templates;

use Illuminate\Support\ServiceProvider;
use Tey\Mod\Facades\Mod;

final class PackageProvider extends ServiceProvider
{
    public function templatesFrom(string $path): void
    {
        Mod::stubs()->folder($path);
    }
}
