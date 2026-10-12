<?php

namespace Tey\Mod\Scaffolds;

/** @internal Source literals for a list or records answer, written on one line. */
final readonly class Literals
{
    public static function php(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : self::php((string) $key).' => ').self::php($item);
            }

            return '['.implode(', ', $items).']';
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => "'".strtr(is_scalar($value) ? (string) $value : '', ['\\' => '\\\\', "'" => "\\'"])."'",
        };
    }

    /** Strings take Prettier's quote: the preferred one unless the text holds more of it than of the other. */
    public static function script(mixed $value, bool $singleQuote): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : $key.': ').self::script($item, $singleQuote);
            }

            return $list ? '['.implode(', ', $items).']' : '{ '.implode(', ', $items).' }';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = is_scalar($value) ? (string) $value : '';
        $preferred = $singleQuote ? "'" : '"';
        $other = $singleQuote ? '"' : "'";
        $quote = substr_count($text, $preferred) > substr_count($text, $other) ? $other : $preferred;

        return $quote.strtr($text, ['\\' => '\\\\', $quote => '\\'.$quote, "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']).$quote;
    }
}
