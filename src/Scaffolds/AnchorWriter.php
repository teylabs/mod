<?php

namespace Tey\Mod\Scaffolds;

use Tey\Mod\Exceptions\GenerationRefused;

/** @internal Marker lines are literal text; existing files are never parsed or reformatted. */
final class AnchorWriter
{
    /** @return array{offset: int, line: string} */
    public function anchor(string $source, string $at, string $path): array
    {
        preg_match_all('/^.*(?:\r\n|\n|\r|$)/m', $source, $lines, PREG_OFFSET_CAPTURE);
        $matches = [];
        foreach ($lines[0] as $number => [$line, $offset]) {
            if (preg_match('/(?<![\w:-])mod:'.preg_quote($at, '/').'(?![\w:-])/', $line) === 1) {
                $matches[$number + 1] = ['offset' => $offset, 'line' => rtrim($line, "\r\n")];
            }
        }
        if ($matches === []) {
            throw GenerationRefused::because(basename($path)." has no // mod:{$at} anchor, so the insert has nowhere to go. Nothing was written.\nPut // mod:{$at} back where new entries belong, then run the command again.");
        }
        if (count($matches) !== 1) {
            throw GenerationRefused::because("{$path} has more than one mod:{$at} anchor, on lines ".implode(' and ', array_keys($matches)).'. Keep one anchor. Nothing was written.');
        }

        return array_values($matches)[0];
    }

    public function insert(string $source, string $at, string $stub, string $path): string
    {
        $anchor = $this->anchor($source, $at, $path);
        $newline = str_contains($source, "\r\n") ? "\r\n" : (str_contains($source, "\r") ? "\r" : "\n");
        $stub = str_replace(["\r\n", "\r"], "\n", $stub);
        $stub = str_replace("\n", $newline, rtrim($stub, "\n")).$newline;

        return substr($source, 0, $anchor['offset']).$stub.substr($source, $anchor['offset']);
    }
}
