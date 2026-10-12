<?php

namespace Tey\Mod\Boost;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Survey\AppSurvey;
use Throwable;

#[IsReadOnly]
/** @internal Survey only the application hosting this tool. */
final class SurveyTool extends Tool
{
    protected string $name = 'mod-survey';

    protected string $description = 'Survey the actual app structure: all file types and classes, unplaced files, references, recurring sets, naming patterns, page folder patterns and declared/inferred entry stems, vendor ownership and git identity. No arguments. Reads only base_path(), excluding secrets and runtime data; writes nothing and makes no network calls.';

    public function __construct(private readonly AppSurvey $survey) {}

    public function handle(Request $request): Response
    {
        if ($request->all() !== []) {
            return Response::error('mod-survey accepts no arguments or paths. It scans only base_path(); outside paths, traversal and symlink targets are refused.');
        }
        try {
            return Response::json($this->survey->read());
        } catch (Throwable $exception) {
            if (! $exception instanceof ModException) {
                throw $exception;
            }

            return Response::error('mod-survey: '.$exception->getMessage());
        }
    }
}
