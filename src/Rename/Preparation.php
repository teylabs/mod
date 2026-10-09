<?php

namespace Tey\Mod\Rename;

use Illuminate\Console\Command;

/** @internal Lane 5 can settle the table choice before the final plan/confirmation. */
interface Preparation
{
    /** @param callable(Request): Result $build */
    public function prepare(Request $request, callable $build, Command $command): Request;
}
