<?php

namespace Tey\Mod\Rename;

/** @internal Read-only analysis of original bytes and identities. */
interface Contributor
{
    public function contribute(Inputs $inputs): Contribution;
}
