<?php

namespace Tey\Mod\Rename;

/** @internal Lane 4 supplies the only mutation owner, including locking before build. */
interface Executor
{
    /** @param callable(Request): Result $build
     * @param  callable(Result): void  $show
     * @param  callable(): bool  $confirm
     */
    public function execute(Request $request, callable $build, callable $show, callable $confirm): int;

    /** Recovery deliberately bypasses normal recipe/source/Git-clean preflight.
     * @param  callable(Result): void  $show
     * @param  callable(): bool  $confirm
     */
    public function recover(Request $request, callable $show, callable $confirm): int;
}
