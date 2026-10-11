<?php

namespace Tey\Mod\Translations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Translation\Translator;
use Tey\Mod\Exceptions\InvalidLayout;
use Tey\Mod\Support\Path;

/** @internal Laravel owns file loading, JSON precedence, overrides and locale fallback. */
final class TranslationNamespaceRegistrar
{
    public static function register(Application $app): void
    {
        $register = function (Translator $translator) use ($app): void {
            $loader = $translator->getLoader();
            try {
                $entries = $app->make(TranslationDirectories::class)->entries();
            } catch (InvalidLayout) {
                return;
            }
            foreach ($entries as $entry) {
                if (array_key_exists($entry['namespace'], $loader->namespaces())) {
                    $app->make('log')->warning('mod translations: skipped group ['.$entry['group'].']; translation namespace ['.$entry['namespace'].'] is already registered.');

                    continue;
                }
                $path = Path::resolve($app->basePath(), $entry['path']);
                $translator->addNamespace($entry['namespace'], $path);
                $translator->addJsonPath($path);
            }
        };
        $app->afterResolving('translator', $register);
        if ($app->resolved('translator')) {
            $register($app->make('translator'));
        }
    }
}
