<?php

namespace Tey\Mod\Layout;

use InvalidArgumentException;

/**
 * @internal Validate the additive compiled frontend owner collection.
 *
 * @phpstan-type Owner array{owner: string, pages: ?string, components: ?string, css: ?string, views: ?string, page_name: ?string, view_namespace: ?string, alias: ?string}
 */
final class FrontendDeclarations
{
    /**
     * @return list<Owner>
     */
    public static function validate(mixed $input): array
    {
        if (! is_array($input)) {
            throw new InvalidArgumentException('Frontend owners must be a collection.');
        }
        $owners = [];
        $aliases = [];
        $names = [];
        foreach ($input as $declaration) {
            if (! is_array($declaration) || ! is_string($declaration['owner'] ?? null)) {
                throw new InvalidArgumentException('Each frontend declaration needs an owner.');
            }
            $record = ['owner' => $declaration['owner'], 'pages' => null, 'components' => null, 'css' => null, 'views' => null, 'page_name' => null, 'view_namespace' => null, 'alias' => null];
            foreach (['pages', 'components', 'css', 'views', 'page_name', 'view_namespace', 'alias'] as $field) {
                $value = $declaration[$field] ?? null;
                if ($value !== null && (! is_string($value) || $value === '')) {
                    throw new InvalidArgumentException('Frontend owner ['.$record['owner'].'] has an invalid '.$field.'.');
                }
                $record[$field] = $value;
            }
            foreach (['pages', 'components', 'css', 'views'] as $field) {
                self::path($record[$field], $record['owner']);
            }
            if ($record['pages'] !== null && ($record['page_name'] === null || preg_match('~^[A-Za-z0-9_{}/?-]+::\{path\}$~D', $record['page_name']) !== 1)) {
                throw new InvalidArgumentException('Frontend owner ['.$record['owner'].'] needs an explicit pageName ending in ::{path}.');
            }
            if ($record['views'] !== null && ($record['view_namespace'] === null || preg_match('/^[A-Za-z0-9_-]+$/D', $record['view_namespace']) !== 1)) {
                throw new InvalidArgumentException('Frontend owner ['.$record['owner'].'] needs an explicit viewNamespace.');
            }
            if ($record['alias'] !== null) {
                if (preg_match('~^[@A-Za-z_][@A-Za-z0-9_./-]*$~D', $record['alias']) !== 1 || $record['components'] === null) {
                    throw new InvalidArgumentException('Frontend owner ['.$record['owner'].'] needs components for its literal alias.');
                }
                $target = $record['components'];
                if (isset($aliases[$record['alias']]) && $aliases[$record['alias']] !== $target) {
                    throw new InvalidArgumentException('Conflicting frontend alias ['.$record['alias'].'] at owner ['.$record['owner'].'].');
                }
                $aliases[$record['alias']] = $target;
            }
            foreach (['page_name', 'view_namespace'] as $field) {
                if ($record[$field] !== null) {
                    $key = $field.':'.$record[$field];
                    if (isset($names[$key])) {
                        throw new InvalidArgumentException('Conflicting '.$field.' at frontend owner ['.$record['owner'].'].');
                    }
                    $names[$key] = true;
                }
            }
            $owners[] = $record;
        }

        return $owners;
    }

    public static function path(?string $path, string $owner): void
    {
        if ($path !== null && preg_match('~^/|^[A-Za-z]:|(?:^|/)(?:\.\.?)(?:/|$)|[\\\\\x00-\x1f\x27"`*]~', $path)) {
            throw new InvalidArgumentException('Frontend owner ['.$owner.'] needs a relative path without traversal or glob characters.');
        }
    }
}
