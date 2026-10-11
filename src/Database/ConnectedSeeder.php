<?php

namespace Tey\Mod\Database;

use Illuminate\Database\Seeder;

/** Optional Laravel base for seeders that share their layout group's connection. @api */
abstract class ConnectedSeeder extends Seeder
{
    use SeedsGroupConnection;
}
