<?php

namespace Tey\Mod\Maintenance;

use RuntimeException;
use Throwable;

/** File-only installation/ownership gate; not a public distribution installer. @internal */
final class Installation
{
    /**
     * @param  array<string, string>  $inputPaths
     * @param  array<string, string>  $compatibility
     */
    public static function prepare(string $root, string $appId, Artifact $target, array $inputPaths, array $compatibility, string $intent, bool $exclusiveCheckout): Journal
    {
        $empty = new Artifact($target->name, $target->version, [], $target->groups, $target->bindings, $target->checks);
        $plan = Checkout::inspect($root, $appId, $empty, $target, $inputPaths, $compatibility);
        $journal = Journal::prepare($plan, $inputPaths, $intent, $exclusiveCheckout);
        try {
            if ($journal->installed() !== null) {
                throw new RuntimeException('Installation cannot replace an existing installed record.');
            }

            return $journal;
        } catch (Throwable $exception) {
            $journal->close();
            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $inputPaths
     * @param  array<string, string>  $compatibility
     */
    public static function upgrade(string $root, string $appId, Artifact $baseline, Artifact $target, array $inputPaths, array $compatibility, string $intent, bool $exclusiveCheckout): Journal
    {
        $plan = Checkout::inspect($root, $appId, $baseline, $target, $inputPaths, $compatibility);
        $journal = Journal::prepare($plan, $inputPaths, $intent, $exclusiveCheckout);
        try {
            InstalledRecord::baseline($journal->installed(), $plan);

            return $journal;
        } catch (Throwable $exception) {
            $journal->close();
            throw $exception;
        }
    }
}
