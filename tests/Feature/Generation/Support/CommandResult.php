<?php

namespace Tey\Mod\Tests\Feature\Generation\Support;

/**
 * The outcome of one artisan call, with PendingCommand-like assertions.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public ?string $basePath = null,
    ) {}

    public function assertSuccessful(): self
    {
        expect($this->exitCode)->toBe(0, $this->output);

        return $this;
    }

    public function assertFailed(): self
    {
        expect($this->exitCode)->not->toBe(0, $this->output);

        return $this;
    }

    public function expectsOutputToContain(string $text): self
    {
        expect($this->normalisedOutput())->toContain($text);

        return $this;
    }

    public function doesntExpectOutputToContain(string $text): self
    {
        expect($this->normalisedOutput())->not->toContain($text);

        return $this;
    }

    /** Console components wrap long lines; compare on single spaces. */
    private function normalisedOutput(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->output);
    }
}
