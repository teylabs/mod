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

it('pins the keep policy while rejecting unsupported strings in plan rows', function () {
    $schema = json_decode(file_get_contents(__DIR__.'/../../Fixtures/schema/plan.json'), true, flags: JSON_THROW_ON_ERROR);
    $file = ['alias' => 'shared', 'type' => 'interface', 'path' => 'app/Shared.php', 'group' => null, 'class' => 'App\\Shared', 'existing' => 'keep', 'exists' => true];
    $data = ['command' => 'mod:recipe', 'group' => 'Inventory', 'name' => 'Widget', 'files' => [$file], 'inserts' => [], 'warnings' => [], 'would_write' => true];
    expect(JsonSchema::errors($data, $schema))->toBe([]);
    $data['files'][0]['existing'] = 'overwrite';
    expect(JsonSchema::errors($data, $schema))->toContain('$.files[0].existing matches no identity schema');
    $data['files'][0]['existing'] = false;
    expect(JsonSchema::errors($data, $schema))->toBe([]);
    unset($data['files'][0]['group']);
    expect(JsonSchema::errors($data, $schema))->toContain('$.files[0].group is required');
});
