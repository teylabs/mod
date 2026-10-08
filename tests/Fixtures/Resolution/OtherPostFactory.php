<?php

namespace Tey\Mod\Tests\Fixtures\Resolution;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
final class OtherPostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [];
    }
}
