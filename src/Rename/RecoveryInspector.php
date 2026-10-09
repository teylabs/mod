<?php

namespace Tey\Mod\Rename;

/** @internal A distinct read-only interface prevents tools from invoking recovery effects. */
interface RecoveryInspector
{
    public function inspect(Request $request): Result;
}
