<?php

use Tey\Mod\Maintenance\Artifact;
use Tey\Mod\Maintenance\FilePlan;

final class AliasPayload implements JsonSerializable
{
    public bool $serialized = false;

    public bool $jsonCalled = false;

    public function __serialize(): array
    {
        $this->serialized = true;

        return [];
    }

    public function jsonSerialize(): mixed
    {
        $this->jsonCalled = true;

        return 'payload';
    }
}

it('refuses referenced metadata before external mutation can introduce executable values', function (string $kind) {
    $value = 'safe';
    $array = ['value' => 'safe'];
    $metadata = match ($kind) {
        'top scalar' => ['value' => &$value],
        'nested scalar' => ['nested' => ['value' => &$value]],
        'top array' => ['array' => &$array],
        'nested array' => ['nested' => ['array' => &$array]],
    };
    $payload = new AliasPayload;
    try {
        $artifact = new Artifact('example/recipe', 'A', [], [], [], ['check'], metadata: $metadata);
    } catch (InvalidArgumentException) {
        expect($payload->serialized)->toBeFalse()->and($payload->jsonCalled)->toBeFalse();

        return;
    }
    $before = $artifact->identity();
    if (str_contains($kind, 'scalar')) {
        $value = $payload;
    } else {
        $array['value'] = $payload;
    }
    $after = $artifact->identity();
    json_encode($artifact->metadata, JSON_THROW_ON_ERROR);
    expect($after)->toBe($before)->and($payload->serialized)->toBeFalse()->and($payload->jsonCalled)->toBeFalse();
})->with(['top scalar', 'nested scalar', 'top array', 'nested array']);

it('rejects aliases that could replace validated values with excessive or cyclic data', function (string $kind) {
    $value = 'safe';
    $metadata = ['nested' => ['value' => &$value]];
    expect(fn () => new Artifact('example/recipe', 'A', [], [], [], ['check'], metadata: $metadata))->toThrow(InvalidArgumentException::class);
    if ($kind === 'bytes') {
        $value = str_repeat('x', 1024 * 1024 + 1);
    } else {
        $value = [];
        $value['self'] = &$value;
    }
})->with(['bytes', 'cycle']);

it('keeps ordinary copied arrays stable even when the caller root variable is an alias', function () {
    $original = ['nested' => ['value' => 'safe']];
    $alias = &$original;
    $artifact = new Artifact('example/recipe', 'A', [], [], [], ['check'], metadata: $alias);
    $before = $artifact->identity();
    $payload = new AliasPayload;
    $original['nested']['value'] = $payload;
    $copy = $artifact->metadata;
    $external = &$copy['nested']['value'];
    $external = str_repeat('x', 1024 * 1024 + 1);
    expect($artifact->identity())->toBe($before)->and($artifact->metadata)->toBe(['nested' => ['value' => 'safe']])
        ->and($payload->serialized)->toBeFalse()->and($payload->jsonCalled)->toBeFalse();
});

it('rejects flat aliases in artifact files modules bindings and required checks', function (string $field) {
    $value = $field === 'files' ? 'safe bytes' : 'safe';
    $files = ['app/One.php' => 'safe bytes'];
    $groups = ['safe'];
    $bindings = ['auth' => 'safe'];
    $checks = ['safe'];
    if ($field === 'files') {
        $files['app/One.php'] = &$value;
    }
    if ($field === 'groups') {
        $groups[0] = &$value;
    }
    if ($field === 'bindings') {
        $bindings['auth'] = &$value;
    }
    if ($field === 'checks') {
        $checks[0] = &$value;
    }
    expect(fn () => new Artifact('example/recipe', 'A', $files, $groups, $bindings, $checks))->toThrow(InvalidArgumentException::class);
})->with(['files', 'groups', 'bindings', 'checks']);

it('rejects aliases in caller-created plan observations inputs and compatibility outcomes', function (string $field) {
    $a = new Artifact('example/recipe', 'A', ['app/One.php' => 'A'], [], [], ['check']);
    $b = new Artifact('example/recipe', 'B', ['app/One.php' => 'B'], [], [], ['check']);
    $current = ['app/One.php' => 'A'];
    $inputs = ['lock' => 'hash'];
    $compatibility = ['php' => 'passed'];
    $value = match ($field) {
        'current' => 'A', 'inputs' => 'hash', 'compatibility' => 'passed'
    };
    if ($field === 'current') {
        $current['app/One.php'] = &$value;
    }
    if ($field === 'inputs') {
        $inputs['lock'] = &$value;
    }
    if ($field === 'compatibility') {
        $compatibility['php'] = &$value;
    }
    expect(fn () => FilePlan::between('one', '/owned/checkout', $a, $b, $current, $inputs, $compatibility))->toThrow(InvalidArgumentException::class);
})->with(['current', 'inputs', 'compatibility']);
