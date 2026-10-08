<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Tey\Mod\Resolution\ModelConventions;
use Tey\Mod\Tests\Fixtures\Resolution\Nested\Comment;
use Tey\Mod\Tests\Fixtures\Resolution\OtherPostFactory;
use Tey\Mod\Tests\Fixtures\Resolution\Post;
use Tey\Mod\Tests\Fixtures\Resolution\PostFactory;

/*
 * The factory name resolver. Laravel's resolver is process-wide, so each
 * test starts and ends without one. The models here are not owned by the
 * layout, so the layout never answers and the delegation is what is
 * observed; ConventionResolutionTest covers the layout's own answer on every
 * built-in layout.
 */
beforeEach(fn () => Factory::flushState());
afterEach(fn () => Factory::flushState());

it('reads the resolver registered before it, once, through reflection', function () {
    expect(ModelConventions::current())->toBeNull();

    $registered = static fn (string $model) => PostFactory::class;
    Factory::guessFactoryNamesUsing($registered);

    expect(ModelConventions::current())->toBe($registered)
        ->and(ModelConventions::register(app())->previous)->toBeInstanceOf(Closure::class);
});

it('delegates to a resolver registered before mod', function () {
    Factory::guessFactoryNamesUsing(static fn (string $model) => PostFactory::class);
    ModelConventions::register(app());

    expect(Factory::resolveFactoryName(Post::class))->toBe(PostFactory::class)
        ->and(ModelConventions::of(ModelConventions::current()))->toBeInstanceOf(ModelConventions::class);
});

it('reproduces Laravel\'s default naming when no resolver was registered', function () {
    foreach ([Post::class, Comment::class] as $model) {
        Factory::flushState();
        $laravel = Factory::resolveFactoryName($model);

        ModelConventions::register(app());

        expect(ModelConventions::of(ModelConventions::current()))->not->toBeNull()
            ->and(Factory::resolveFactoryName($model))->toBe($laravel)
            ->and(ModelConventions::laravelDefault($model))->toBe($laravel);
    }
});

it('follows Factory::useNamespace() in the default, as Laravel does', function () {
    Factory::useNamespace('Factories\\');
    ModelConventions::register(app());

    expect(Factory::resolveFactoryName(Post::class))->toBe('Factories\\Tey\\Mod\\Tests\\Fixtures\\Resolution\\PostFactory');
});

it('leaves a resolver registered after mod in charge', function () {
    ModelConventions::register(app());
    Factory::guessFactoryNamesUsing(static fn (string $model) => OtherPostFactory::class);

    expect(Factory::resolveFactoryName(Post::class))->toBe(OtherPostFactory::class)
        ->and(ModelConventions::of(ModelConventions::current()))->toBeNull();
});

it('unwraps an earlier application\'s resolver instead of chaining it', function () {
    Factory::guessFactoryNamesUsing(static fn (string $model) => PostFactory::class);

    $first = ModelConventions::register(app());
    $second = ModelConventions::register(app());

    expect(ModelConventions::of(ModelConventions::current()))->toBe($second)
        ->and(ModelConventions::of($second->previous))->toBeNull()
        ->and($second->previous)->toEqual($first->previous)
        ->and(Factory::resolveFactoryName(Post::class))->toBe(PostFactory::class);
});

it('restores the registered resolver after computing Laravel\'s default', function () {
    $conventions = ModelConventions::register(app());
    $registered = ModelConventions::current();

    ModelConventions::laravelDefault(Post::class);

    expect(ModelConventions::current())->toBe($registered)
        ->and(ModelConventions::of($registered))->toBe($conventions);
});
