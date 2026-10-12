<?php

namespace Tey\Mod\Scaffolds;

/** @internal Reads singleQuote from the app's Prettier config as text; no config file is ever executed. */
final readonly class PrettierConfig
{
    /** In the order Prettier searches for them. */
    private const FILES = [
        '.prettierrc', '.prettierrc.json', '.prettierrc.yaml', '.prettierrc.yml', '.prettierrc.json5',
        '.prettierrc.js', '.prettierrc.ts', '.prettierrc.mjs', '.prettierrc.mts', '.prettierrc.cjs', '.prettierrc.cts',
        'prettier.config.js', 'prettier.config.ts', 'prettier.config.mjs', 'prettier.config.mts', 'prettier.config.cjs', 'prettier.config.cts',
        '.prettierrc.toml',
    ];

    public function __construct(private string $basePath) {}

    /** Prettier's default is double quotes. */
    public function singleQuote(): bool
    {
        $package = $this->basePath.'/package.json';
        if (is_file($package)) {
            $json = json_decode((string) file_get_contents($package), true);
            if (is_array($json) && is_array($json['prettier'] ?? null)) {
                return ($json['prettier']['singleQuote'] ?? false) === true;
            }
        }
        foreach (self::FILES as $file) {
            $path = $this->basePath.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            $json = json_decode($source, true);
            if (is_array($json)) {
                return ($json['singleQuote'] ?? false) === true;
            }
            $pattern = match (true) {
                str_ends_with($file, '.yaml'), str_ends_with($file, '.yml') => '/^singleQuote:\s*(true|false)\b/m',
                str_ends_with($file, '.toml') => '/^singleQuote\s*=\s*(true|false)\b/m',
                in_array($file, ['.prettierrc', '.prettierrc.json', '.prettierrc.json5'], true) => '/^\s*["\']?singleQuote["\']?\s*:\s*(true|false)\b/m',
                default => '/\bsingleQuote\s*:\s*(true|false)\b/',
            };

            return preg_match($pattern, $source, $match) === 1 && $match[1] === 'true';
        }

        return false;
    }
}
