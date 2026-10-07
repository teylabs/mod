<?php

namespace Tey\Mod\Discovery;

use Tey\Mod\Placement\Root;
use Tey\Mod\Placement\TemplateRule;
use Tey\Mod\Preset\Preset;

/**
 * A digest of everything in a preset that decides ownership: roots,
 * exclusions, dimensions, kinds and placement rules.
 *
 * Callback (opaque) rules are recorded by kind and root only; a closure's
 * behaviour cannot be hashed, so rebuild the discovery cache on deploy
 * rather than trusting automatic invalidation.
 *
 * @internal used by Discovery for cache validation.
 */
final readonly class PresetFingerprint
{
    public static function of(Preset $preset): string
    {
        $describeRoot = static fn (Root $root): string => ($root->namespace ?? '').'|'.$root->path;

        $roots = array_map($describeRoot, $preset->roots());
        ksort($roots);

        $kinds = [];

        foreach ($preset->kinds() as $id => $kind) {
            $rule = $preset->rule($id);
            $kinds[$id] = [
                $kind->shape->value,
                $kind->namePolicy->describe(),
                $kind->command,
                $rule instanceof TemplateRule ? $rule->pattern() : 'opaque|'.$describeRoot($rule->root()),
                $rule->priority(),
                $rule->dimensions(),
            ];
        }

        ksort($kinds);

        return hash('sha256', serialize([
            'roots' => $roots,
            'excluded' => array_map($describeRoot, $preset->excludedRoots()),
            'dimensions' => $preset->dimensionNames(),
            'kinds' => $kinds,
        ]));
    }

    /**
     * @param  list<DiscoveryDefinition>  $definitions
     */
    public static function ofDefinitions(array $definitions): string
    {
        return hash('sha256', implode("\n", array_map(static fn (DiscoveryDefinition $definition): string => $definition->identity(), $definitions)));
    }
}
