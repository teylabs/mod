<?php

namespace Tey\Mod\Rename\Tables;

use Tey\Mod\Rename\ClusterMember;

/** @internal Source-only table inference; null names mean unsafe custom logic. */
final readonly class ModelTable
{
    public function __construct(public ClusterMember $member, public int $line, public ?string $old, public ?string $new, public bool $explicit = false) {}

    public function changes(): bool
    {
        return $this->old !== null && $this->new !== null && $this->old !== $this->new;
    }
}
