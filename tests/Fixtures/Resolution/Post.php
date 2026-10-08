<?php

namespace Tey\Mod\Tests\Fixtures\Resolution;

use Illuminate\Database\Eloquent\Model;

/**
 * A model no layout owns: the factory resolver's delegation is what answers for it.
 */
final class Post extends Model {}
