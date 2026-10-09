<?php

use Tey\Mod\Tests\Support\PublishedApi;

it('pins every published signature and rejects internal result types and vocabulary', function () {
    $api = PublishedApi::capture();
    expect($api)->not->toBeEmpty()
        ->and(PublishedApi::violations($api))->toBe([])
        ->and(json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")
        ->toBe(str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../../Fixtures/published-api.json')));
});

it('extracts parameter name default type and return changes and rejects removed publication', function () {
    $original = PublishedApi::method(new ReflectionMethod(SnapshotOriginal::class, 'example'));
    foreach ([SnapshotNameChanged::class, SnapshotDefaultChanged::class, SnapshotTypeChanged::class, SnapshotReturnChanged::class] as $class) {
        expect(PublishedApi::method(new ReflectionMethod($class, 'example')))->not->toBe($original);
    }
    expect(fn () => PublishedApi::method(new ReflectionMethod(SnapshotUnpublished::class, 'example')))->toThrow(InvalidArgumentException::class);
});

class SnapshotOriginal
{
    /** @api */
    public function example(string $name = 'original'): string
    {
        return $name;
    }
}
class SnapshotNameChanged
{
    /** @api */
    public function example(string $renamed = 'original'): string
    {
        return $renamed;
    }
}
class SnapshotDefaultChanged
{
    /** @api */
    public function example(string $name = 'changed'): string
    {
        return $name;
    }
}
class SnapshotTypeChanged
{
    /** @api */
    public function example(?string $name = 'original'): string
    {
        return $name ?? '';
    }
}
class SnapshotReturnChanged
{
    /** @api */
    public function example(string $name = 'original'): ?string
    {
        return $name;
    }
}
class SnapshotUnpublished
{
    public function example(string $name = 'original'): string
    {
        return $name;
    }
}
