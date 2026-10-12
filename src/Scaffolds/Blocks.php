<?php

namespace Tey\Mod\Scaffolds;

use Closure;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;

/**
 * Template blocks: {{ #name }}…{{ /name }} repeats over a list of records, or renders
 * when a boolean is true; {{ ^name }}…{{ /name }} renders when it is false or empty.
 *
 * A tag alone on its line leaves nothing behind, and the block's lines take the
 * opening tag's indentation. Tags that share a line with other text render inline, and an
 * inline repeat takes sep="…" to put text between its items: {{ #sorts sep=", " }}.
 *
 * @internal
 */
final class Blocks
{
    private const TAG = '/\{\{\s*([#^\/])\s*([\w.-]+)(?:\s+([^}]*?))?\s*\}\}/';

    public static function present(string $stub): bool
    {
        return preg_match(self::TAG, $stub) === 1;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Closure(string, array<string, mixed>): string  $substitute  replaces plain placeholders in text outside any tag
     */
    public function render(string $stub, array $values, Closure $substitute): string
    {
        $windows = str_contains($stub, "\r\n");
        $output = $this->expand($windows ? str_replace("\r\n", "\n", $stub) : $stub, $values, $substitute);

        return $windows ? str_replace("\n", "\r\n", $output) : $output;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  Closure(string, array<string, mixed>): string  $substitute
     */
    private function expand(string $text, array $values, Closure $substitute): string
    {
        $output = '';
        $position = 0;
        foreach ($this->sections($text) as $section) {
            $output .= $substitute(substr($text, $position, $section['from'] - $position), $values);
            $position = $section['to'];
            $body = $section['line'] ? $this->rebase($section['body'], $section['indent']) : $section['body'];
            $items = [];
            foreach ($this->scopes($section['name'], $section['inverted'], $values, $section['sep']) as $scope) {
                $items[] = $this->expand($body, $scope, $substitute);
            }
            $output .= implode($section['sep'] ?? '', $items);
        }

        return $output.$substitute(substr($text, $position), $values);
    }

    /**
     * The top-level sections of the text, with the span each one removes.
     *
     * @return list<array{name: string, inverted: bool, line: bool, indent: string, sep: ?string, body: string, from: int, to: int}>
     */
    private function sections(string $text): array
    {
        preg_match_all(self::TAG, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tags = [];
        foreach ($matches as $match) {
            [$tag, $start] = $match[0];
            $end = $start + strlen($tag);
            $lineStart = ($newline = strrpos(substr($text, 0, $start), "\n")) === false ? 0 : $newline + 1;
            $lineEnd = ($next = strpos($text, "\n", $end)) === false ? strlen($text) : $next;
            $before = substr($text, $lineStart, $start - $lineStart);
            $alone = trim($before, " \t") === '' && trim(substr($text, $end, $lineEnd - $end), " \t") === '';
            $tags[] = [
                'type' => $match[1][0], 'name' => $match[2][0], 'options' => $match[3][0] ?? '', 'start' => $start, 'end' => $end,
                'alone' => $alone, 'indent' => $before,
                'from' => $alone ? $lineStart : $start,
                'to' => $alone ? min($lineEnd + 1, strlen($text)) : $end,
            ];
        }
        $sections = [];
        $open = [];
        foreach ($tags as $tag) {
            if ($tag['type'] !== '/') {
                $open[] = $tag;

                continue;
            }
            if ($tag['options'] !== '') {
                throw GenerationRefused::because("Template block tag {{ /{$tag['name']} }} takes no options. Nothing was written.");
            }
            $opening = array_pop($open);
            if ($opening === null) {
                throw GenerationRefused::because("Template block {{ /{$tag['name']} }} closes a block that was never opened. Nothing was written.");
            }
            if ($opening['name'] !== $tag['name']) {
                throw GenerationRefused::because("Template block {{ {$opening['type']}{$opening['name']} }} is closed by {{ /{$tag['name']} }}. Close blocks in the order they were opened. Nothing was written.");
            }
            if ($open !== []) {
                continue;
            }
            if ($opening['alone'] !== $tag['alone']) {
                throw GenerationRefused::because("Template block {{ {$opening['type']}{$tag['name']} }} has one tag alone on its line and one beside other text. Put both tags on their own lines, or keep both inline. Nothing was written.");
            }
            $line = $opening['alone'];
            $sep = $this->separator($opening, $line);
            $sections[] = [
                'name' => $tag['name'], 'inverted' => $opening['type'] === '^', 'line' => $line, 'indent' => $opening['indent'], 'sep' => $sep,
                'body' => substr($text, $opening['to'], $tag['from'] - $opening['to']),
                'from' => $opening['from'], 'to' => $tag['to'],
            ];
        }
        if ($open !== []) {
            $unclosed = $open[0];
            throw GenerationRefused::because("Template block {{ {$unclosed['type']}{$unclosed['name']} }} is never closed. Add {{ /{$unclosed['name']} }}. Nothing was written.");
        }

        return $sections;
    }

    /**
     * The sep="…" of an opening tag, or null. Anything else in the tag is refused.
     *
     * @param  array{type: string, name: string, options: string}  $opening
     */
    private function separator(array $opening, bool $line): ?string
    {
        $options = $opening['options'];
        if ($options === '') {
            return null;
        }
        $tag = "{{ {$opening['type']}{$opening['name']} }}";
        if ($opening['type'] !== '#') {
            throw GenerationRefused::because("Template block {$tag} takes no options. Only {{ #name sep=\"…\" }} does. Nothing was written.");
        }
        if (preg_match('/^([\w-]+)="([^"]*)"$/', $options, $found) !== 1) {
            throw GenerationRefused::because("Template block {$tag} has malformed options ({$options}). Write sep=\"…\" with a closed double quote. Nothing was written.");
        }
        if ($found[1] !== 'sep') {
            throw GenerationRefused::because("Template block {$tag} has an unknown option '{$found[1]}'. Only sep is supported. Nothing was written.");
        }
        if ($line) {
            throw GenerationRefused::because("Template block {$tag} puts sep on a block whose tags are alone on their lines. Separators are for inline blocks; keep both tags beside other text. Nothing was written.");
        }

        return $found[2];
    }

    /**
     * Strip the body's own common indentation and put the opening tag's in its place.
     */
    private function rebase(string $body, string $indent): string
    {
        $lines = explode("\n", $body);
        $base = null;
        foreach ($lines as $line) {
            if (trim($line, " \t") === '') {
                continue;
            }
            $leading = substr($line, 0, strspn($line, " \t"));
            if ($base === null) {
                $base = $leading;

                continue;
            }
            $length = 0;
            $max = min(strlen($base), strlen($leading));
            while ($length < $max && $base[$length] === $leading[$length]) {
                $length++;
            }
            $base = substr($base, 0, $length);
        }
        $base ??= '';

        return implode("\n", array_map(static fn (string $line): string => trim($line, " \t") === '' ? '' : $indent.substr($line, strlen($base)), $lines));
    }

    /**
     * One scope per rendering of the block: the item's fields over the outer values.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function scopes(string $name, bool $inverted, array $values, ?string $sep = null): array
    {
        if (! array_key_exists($name, $values)) {
            throw GenerationRefused::because("Template block {{ #{$name} }} names no question, member or record field. Check the spelling. Nothing was written.");
        }
        $value = $values[$name];
        if (is_bool($value) || $value instanceof ResolvedArtifact || $value instanceof SkippedMember) {
            if ($sep !== null) {
                throw GenerationRefused::because("Template block {{ #{$name} }} has sep, but {$name} is a boolean or member, so nothing repeats. Nothing was written.");
            }
            $on = is_bool($value) ? $value : ! $value instanceof SkippedMember;

            return $on === $inverted ? [] : [$values];
        }
        if (! is_array($value)) {
            throw GenerationRefused::because("Template block {{ #{$name} }} is text, so it can't be repeated or switched on. Use a confirm question, a records question or a member.");
        }
        if ($inverted) {
            return $value === [] ? [$values] : [];
        }
        $scopes = [];
        foreach ($value as $record) {
            if (! is_array($record)) {
                throw GenerationRefused::because("Template block {{ #{$name} }} repeats records, and {$name} is a plain list. Ask for it with type: 'records', or print it with {{ {$name}.json }}. Nothing was written.");
            }
            $scopes[] = [...$values, ...$record];
        }

        return $scopes;
    }
}
