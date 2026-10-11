<?php

namespace Tey\Mod\Boost;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Survey\AppSurvey;
use Tey\Mod\Survey\OutOfScope;

#[IsReadOnly]
/** @internal Survey how the application is actually organized. */
final class SurveyTool extends Tool
{
    protected string $name = 'mod-survey';

    protected string $description = 'Survey how this application is actually organized: the folders, namespaces and classes of every file type, files no file type places, a reference graph (imports, Inertia pages, routes, policies), recurring file sets and names, vendor classes the app uses, and the git ref scanned. Reads only this application, never .env, storage, keys, logs or databases. This tool writes nothing.';

    public function __construct(private readonly AppSurvey $survey) {}

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Optional folder inside this application to survey, relative to its root, e.g. "app". Paths outside the application are refused.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $path = $request->get('path');
        if ($path !== null && ! is_string($path)) {
            return Response::error('mod-survey needs path as a string relative to the application root, or no path.');
        }
        try {
            return Response::json($this->survey->read($path));
        } catch (OutOfScope $exception) {
            return Response::error($exception->getMessage());
        } catch (ModException $exception) {
            return Response::error('mod-survey: '.$exception->getMessage());
        }
    }
}
