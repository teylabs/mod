<?php

use Tey\Mod\Tests\Support\JsonSchema;

it('pins required plan keys while accepting additive fields', function () {
    $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
    $data = ['command' => 'mod:model', 'group' => null, 'name' => 'Widget', 'files' => [], 'inserts' => [], 'warnings' => [], 'would_write' => true];
    expect(JsonSchema::errors($data, $schema))->toBe([]);
    $data['future'] = true;
    expect(JsonSchema::errors($data, $schema))->toBe([]);
    unset($data['warnings']);
    expect(JsonSchema::errors($data, $schema))->toContain('$.warnings is required');
});
