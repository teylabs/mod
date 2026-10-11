<?php

namespace Tey\Mod\Database;

/** Use on a Laravel seeder or shared seeder base to scope DB and Schema calls. @api */
trait SeedsGroupConnection
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return mixed
     *
     * @internal
     */
    public function __invoke(array $parameters = [])
    {
        $connection = app(GroupConnections::class)->forClass(static::class);
        $database = app('db');
        $previous = $database->getDefaultConnection();
        if ($connection !== null) {
            $database->setDefaultConnection($connection);
        }
        try {
            return parent::__invoke($parameters);
        } finally {
            $database->setDefaultConnection($previous);
        }
    }
}
