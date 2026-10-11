<?php

namespace Tey\Mod\Install;

use Tey\Mod\Layout\CompiledLayout;

/** @internal One effective declaration for providers and mounted frontend owners. */
final class FrontendWiring
{
    public static function forLayout(Wiring $wiring, CompiledLayout $layout): Wiring
    {
        $effective = clone $wiring;
        foreach ($layout->frontendOwners() as $owner) {
            if ($owner['alias'] !== null && $owner['components'] !== null) {
                $effective->alias($owner['alias'], $owner['components']);
            }
            foreach (['components', 'pages', 'views'] as $field) {
                if ($owner[$field] !== null) {
                    $pattern = $owner[$field];
                    foreach ($layout->dimensions() as $dimension) {
                        $pattern = str_replace(['{'.$dimension->name.'}', '{'.$dimension->name.'?}'], $dimension->multi ? '**' : '*', $pattern);
                    }
                    $effective->sourcePattern($pattern);
                }
            }
        }

        return $effective;
    }
}
