<?php

namespace Tey\Mod\Boost;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;
use Tey\Mod\Survey\AppFit;

#[IsReadOnly]
/** @internal Check an uninstalled layout or recipe against the hosting app. */
final class FitTool extends Tool
{
    protected string $name = 'mod-fit';

    protected string $description = 'Check a proposed layout and/or recipe without installing it. Return owned/unowned files, conflicts, path/mechanism variances, missing members, outside references and unowned code configuration. Reads only base_path(), excluding secrets/runtime data; vendor only classifies references. No writes, source execution or network calls.';

    public function __construct(private readonly AppFit $fit) {}

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'layout' => $schema->object([
                'base' => $schema->string()->enum((new BuiltInLayouts)->names())->required(),
                'path' => $schema->string()->description('Optional group path, as in Layout::path().'),
                'mounts' => $schema->array()->items($schema->object([
                    'id' => $schema->string()->required(), 'namespace' => $schema->string()->nullable()->required(), 'path' => $schema->string()->required(),
                ])->withoutAdditionalProperties()),
                'types' => $schema->array()->items($schema->object([
                    'id' => $schema->string()->required(), 'in' => $schema->string()->required(),
                    'suffix' => $schema->string(), 'extension' => $schema->string(),
                ])->withoutAdditionalProperties()),
            ])->withoutAdditionalProperties()->description('Isolated built-in layout with optional mounts and placement overrides; never registered.'),
            'recipe' => $schema->object([
                'name' => $schema->string()->required(),
                'members' => $schema->array()->items($schema->object([
                    'id' => $schema->string()->required(), 'path' => $schema->string()->required(),
                    'class' => $schema->string(), 'page' => $schema->string(),
                ])->withoutAdditionalProperties())->required(),
                'tables' => $schema->array()->items($schema->string()),
            ])->withoutAdditionalProperties()->description('Concrete expected member paths for one scaffold invocation; optional class/page identities and table names. Supply layout and/or recipe.'),
            'variances' => $schema->array()->items($schema->object([
                'member' => $schema->string()->required(), 'path' => $schema->string()->required(),
                'mechanism' => $schema->string()->description('Agent explanation for the alternative capability, e.g. registration-flow.'),
            ])->withoutAdditionalProperties())->description('Agent evidence for a relocated/renamed role or different mechanism; path must be an existing permitted app file.'),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return Response::json($this->fit->read($request->all()));
        } catch (ModException $exception) {
            return Response::error('mod-fit: '.$exception->getMessage());
        }
    }
}
