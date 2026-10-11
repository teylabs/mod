<?php

namespace Tey\Mod\Database;

use Illuminate\Database\Eloquent\Model;

/** Optional Eloquent base for models that share their layout group's connection. @api */
abstract class ConnectedModel extends Model
{
    use UsesGroupConnection;
}
