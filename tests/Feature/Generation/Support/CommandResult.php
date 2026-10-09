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
        expect((string) preg_replace('/\s+/', ' ', $this->normalisedOutput()))->toContain($text);

        return $this;
    }

    public function doesntExpectOutputToContain(string $text): self
    {
        expect((string) preg_replace('/\s+/', ' ', $this->normalisedOutput()))->not->toContain($text);

        return $this;
    }

    /** Preserve console spacing while making workspace paths portable. */
    public function normalisedOutput(): string
    {
        $roots = $this->basePath === null ? [] : [$this->basePath];
        if ($this->basePath !== null && ($real = realpath($this->basePath)) !== false) {
            $roots[] = $real;
        }

        return (string) preg_replace_callback('/\[([^\]\r\n]+)\]/', function (array $match) use ($roots): string {
            $path = str_replace('\\', '/', $match[1]);
            foreach ($roots as $root) {
                $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
                $matches = PHP_OS_FAMILY === 'Windows'
                    ? strncasecmp($path, $prefix, strlen($prefix)) === 0
                    : str_starts_with($path, $prefix);
                if ($matches) {
                    return '['.substr($path, strlen($prefix)).']';
                }
            }

            // Brackets also contain namespaces and class references; keep those intact.
            if (! str_contains($match[1], '/') && ! str_contains($match[1], ':')
                && ! str_starts_with($path, '//') && pathinfo($path, PATHINFO_EXTENSION) === '') {
                return $match[0];
            }

            return '['.$path.']';
        }, str_replace("\r\n", "\n", $this->output));
    }
}
