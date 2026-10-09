<?php

namespace Tey\Mod\Rename\Tables;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Tey\Mod\Rename\Contributors;

/** @internal Registered by the integration lane, keeping shared provider edits sequential. */
final class ServiceProvider extends LaravelServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Candidate::class, fn (): Candidate => new Candidate($this->app->make('migration.creator')));
        $this->app->bind(\Tey\Mod\Rename\Preparation::class, Preparation::class);
        $this->app->afterResolving(Contributors::class, function (Contributors $contributors): void {
            $contributors->set('tables', $this->app->make(Contributor::class));
        });
    }
}
