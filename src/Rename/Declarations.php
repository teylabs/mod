<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Rename\Php\References;

/** @internal Named PHP contributor registration, retained for integration compatibility. */
final class Declarations implements Contributor
{
    public function contribute(Inputs $inputs): Contribution
    {
        return (new References)->contribute($inputs);
    }
}
