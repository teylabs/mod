<?php

namespace Tey\Mod\Rename\Frontend;

/** @internal Controlled subprocess seam; never installs dependencies or writes sources. */
interface Runner
{
    public function run(string $basePath, string $input): RunResult;
}
