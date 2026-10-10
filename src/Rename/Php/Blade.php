<?php

namespace Tey\Mod\Rename\Php;

use PhpToken;

/** @internal PHP regions and directive expressions, with frontend literal bytes excluded. */
final class Blade
{
    /** @return array<int, PhpToken> */
    public function tokens(string $bytes): array
    {
        // Mask markup with equal-length spaces so token positions remain original bytes.
        $masked = preg_replace('/[^\r\n]/', ' ', $bytes) ?? '';
        $visible = preg_replace_callback('/{{--.*?--}}|(?<!@)@verbatim\b.*?@endverbatim/s', static fn (array $m): string => preg_replace('/[^\r\n]/', ' ', $m[0]) ?? '', $bytes) ?? '';
        preg_match_all('/<\?php\b(.*?)(?:\?>|$)|<\?=(.*?)(?:\?>|$)|(?<!@)@php\b(?!\s*\()(.*?)@endphp|(?<!@)\{!!(.*?)!!\}|(?<!@)\{\{(.*?)\}\}/s', $visible, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        foreach ($matches as $match) {
            $whole = $match[0][0] ?? '';
            $visible = substr_replace($visible, preg_replace('/[^\r\n]/', ' ', $whole) ?? '', $match[0][1], strlen($whole));
            foreach ($match as $capture => [$body, $position]) {
                if ($capture === 0) {
                    continue;
                }
                if ($body === null) {
                    continue;
                }
                $masked = substr_replace($masked, $body, $position, strlen($body));
                // Echo regions are expressions; raw blocks can end in a close tag without ';'.
                $end = $position + strlen($body);
                if (! str_ends_with(rtrim($body), ';')) {
                    $masked = substr_replace($masked, ';', $end, 1);
                }
                break;
            }
        }
        $insertions = [];
        $headers = [];
        preg_match_all('/(?<!@)@(if|elseif|unless|while|for|foreach|forelse|isset|empty|php|include|includeif|includewhen|includeunless|includefirst|extends|component|each|slot|props|aware|class|style|yield|section|push|prepend|stack|auth|guest|can|cannot|canany|switch|case|json|js|vite)\b\s*(?=\()/si', $visible, $directives, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($directives as $directive) {
            $start = $directive[0][1];
            $open = $start + strlen($directive[0][0]);
            $fragment = PhpToken::tokenize('<?php '.substr($visible, $open));
            $depth = 0;
            $close = null;
            foreach ($fragment as $token) {
                if ($token->text === '(') {
                    $depth++;
                }
                if ($token->text === ')' && --$depth === 0) {
                    $close = $open + $token->pos - 6;
                    break;
                }
            }
            if ($close === null) {
                throw new \ParseError('Unclosed Blade PHP expression.');
            }
            $keyword = strtolower($directive[1][0]);
            $header = match ($keyword) {
                'elseif', 'unless' => 'if',
                'forelse' => 'foreach',
                'php' => '',
                'if', 'while', 'for', 'foreach', 'isset', 'empty' => $keyword,
                default => 'f',
            };
            if ($header === 'f') {
                $headers[$start + 1] = in_array($keyword, ['include', 'includeif', 'includewhen', 'includeunless', 'includefirst', 'extends', 'component', 'each'], true) ? 'blade-identity' : 'blade-expression';
            }
            $headerBytes = preg_replace('/[^\r\n]/', ' ', substr($bytes, $start, $open - $start)) ?? '';
            if ($header !== '') {
                $headerBytes = substr_replace($headerBytes, $header, 1, strlen($header));
            }
            $masked = substr_replace($masked, $headerBytes, $start, $open - $start);
            $masked = substr_replace($masked, substr($bytes, $open, $close - $open + 1), $open, $close - $open + 1);
            $insertions[] = $close + 1;
        }
        sort($insertions);
        foreach (array_reverse($insertions) as $position) {
            $masked = substr_replace($masked, ';', $position, 0);
        }
        $tokens = PhpToken::tokenize('<?php '.$masked, TOKEN_PARSE);
        foreach ($tokens as $token) {
            $position = $token->pos - 6;
            $shift = 0;
            foreach ($insertions as $insertion) {
                if ($insertion + $shift > $position) {
                    break;
                }
                $shift++;
            }
            $token->pos = $position - $shift;
            if (isset($headers[$token->pos]) && $token->text === 'f') {
                // Mark only the synthetic call; direct directive literals belong to frontend.
                $token->id = T_INLINE_HTML;
                $token->text = $headers[$token->pos];
            }
        }

        return $tokens;
    }
}
