<?php

namespace Tey\Mod\Database;

/** Use on an Eloquent model or shared model base; factories inherit its connection. @api */
trait UsesGroupConnection
{
    /** @internal Eloquent calls this initializer for each new model instance. */
    public function initializeUsesGroupConnection(): void
    {
        if ($this->getConnectionName() === null) {
            $connection = app(GroupConnections::class)->forClass(static::class);
            if ($connection !== null) {
                $this->setConnection($connection);
            }
        }
    }
}
