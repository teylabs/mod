<?php

namespace Tey\Mod\Rename\Frontend;

use PhpToken;
use Tey\Mod\Rename\Checklist;
use Tey\Mod\Rename\Contribution;
use Tey\Mod\Rename\Edit;
use Tey\Mod\Rename\InputFile;

/** @internal Blade-only literal nodes; PHP regions and expressions belong to the PHP contributor. */
final class Blade
{
    /** @param array<string, string> $names
     * @param  array<string, string>  $tags
     */
    public static function contribute(InputFile $file, array $names, array $tags): Contribution
    {
        $edits = [];
        $checklist = [];
        foreach (PhpToken::tokenize($file->bytes) as $token) {
            if ($token->id !== T_INLINE_HTML) {
                continue;
            }
            $source = $token->text;
            $size = strlen($source);
            for ($i = 0; $i < $size; $i++) {
                $end = null;
                foreach (['{{--' => '--}}', '{{' => '}}', '{!!' => '!!}', '<!--' => '-->', '@verbatim' => '@endverbatim', '@php' => '@endphp'] as $open => $close) {
                    if (substr($source, $i, strlen($open)) === $open) {
                        $end = strpos($source, $close, $i + strlen($open));
                        if ($end === false) {
                            return new Contribution;
                        }
                        $i = $end + strlen($close) - 1;
                        break;
                    }
                }
                if ($end !== null) {
                    continue;
                }
                if ($source[$i] === '@' && ($i === 0 || $source[$i - 1] !== '@') && preg_match('/\G@(include|includeIf|includeWhen|includeUnless|extends|component|each)\s*\(/A', $source, $match, 0, $i) === 1) {
                    $start = $i + strlen($match[0]);
                    $cursor = $size;
                    $depth = 1;
                    foreach (PhpToken::tokenize('<?php '.substr($source, $start)) as $argumentToken) {
                        if ($argumentToken->id === T_CLOSE_TAG) {
                            break;
                        }
                        if ($argumentToken->text === '(') {
                            $depth++;
                        } elseif ($argumentToken->text === ')' && --$depth === 0) {
                            $cursor = $start + $argumentToken->pos - strlen('<?php ');
                            break;
                        }
                    }
                    if ($cursor >= $size) {
                        return new Contribution;
                    }
                    $arguments = array_values(array_filter(PhpToken::tokenize('<?php '.substr($source, $start, $cursor - $start)), static fn (PhpToken $t): bool => ! $t->isIgnorable() && $t->id !== T_OPEN_TAG));
                    $index = in_array($match[1], ['includeWhen', 'includeUnless'], true) ? self::secondArgument($arguments) : 0;
                    $literal = $arguments[$index] ?? null;
                    $next = $arguments[$index + 1] ?? null;
                    $static = $literal !== null && $literal->id === T_CONSTANT_ENCAPSED_STRING && ($next === null || $next->text === ',');
                    if (! $static) {
                        $offset = $token->pos + $i;
                        $suggestion = $literal !== null && $literal->id === T_CONSTANT_ENCAPSED_STRING ? ($names[substr($literal->text, 1, -1)] ?? null) : null;
                        $checklist[] = new Checklist($file->path, substr_count(substr($file->bytes, 0, $offset), "\n") + 1, 'blade-identity', 'Computed Blade identity requires review.', $suggestion);
                    }
                    if ($static) {
                        $value = substr($literal->text, 1, -1);
                        if (isset($names[$value]) && ! str_contains($value, '\\') && ! str_contains($names[$value], $literal->text[0])) {
                            $offset = $token->pos + $start + $literal->pos - strlen('<?php ') + 1;
                            $edits[] = new Edit($file->path, $offset, $value, $names[$value], 'blade-identity', substr_count(substr($file->bytes, 0, $offset), "\n") + 1);
                        }
                    }
                    $i = $cursor;
                } elseif ($source[$i] === '<' && preg_match('/\\G<\\/?([A-Za-z][A-Za-z0-9_.:\\-]*)(?=[\\s\\/>])/A', $source, $match, 0, $i) === 1) {
                    $cursor = $i + strlen($match[0]);
                    $quote = null;
                    for (; $cursor < $size; $cursor++) {
                        $char = $source[$cursor];
                        if ($quote !== null) {
                            if ($char === $quote) {
                                $quote = null;
                            }
                        } elseif ($char === "'" || $char === '"') {
                            $quote = $char;
                        } elseif ($char === '>') {
                            break;
                        }
                    }
                    if ($cursor >= $size) {
                        return new Contribution;
                    }
                    if ($match[1] === 'x-dynamic-component') {
                        $offset = $token->pos + $i;
                        $checklist[] = new Checklist($file->path, substr_count(substr($file->bytes, 0, $offset), "\n") + 1, 'blade-identity', 'Dynamic Blade component requires review.');
                    }
                    if (isset($tags[$match[1]])) {
                        $offset = $token->pos + $i + (str_starts_with($match[0], '</') ? 2 : 1);
                        $edits[] = new Edit($file->path, $offset, $match[1], $tags[$match[1]], 'blade-identity', substr_count(substr($file->bytes, 0, $offset), "\n") + 1);
                    }
                    if (in_array(strtolower($match[1]), ['script', 'style'], true) && ! str_starts_with($match[0], '</')) {
                        $close = stripos($source, '</'.$match[1], $cursor);
                        if ($close === false) {
                            return new Contribution;
                        }
                        $cursor = $close - 1;
                    }
                    $i = $cursor;
                }
            }
        }

        return new Contribution($edits, $checklist);
    }

    /** @param list<PhpToken> $tokens */
    private static function secondArgument(array $tokens): int
    {
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if (in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token->text === ',' && $depth === 0) {
                return $index + 1;
            }
        }

        return count($tokens);
    }
}
