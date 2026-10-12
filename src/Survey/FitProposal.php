<?php

namespace Tey\Mod\Survey;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Tey\Mod\Exceptions\ModException;
use Tey\Mod\Layout\BuiltIn\BuiltInLayouts;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Layout\FileType;
use Tey\Mod\Layout\Layout;
use Tey\Mod\Pages\PageEntries;
use Tey\Mod\Support\Path;

/**
 * @internal A data-only proposal; never register or execute an app recipe.
 *
 * @phpstan-type Member array{id: string, path: string, class?: string, page?: string, pattern?: string}
 * @phpstan-type Variance array{member: string, path: string, mechanism?: string}
 */
final readonly class FitProposal
{
    /** @param list<Member> $members
     * @param  list<string>  $tables
     * @param  list<Variance>  $variances
     */
    private function __construct(
        public ?CompiledLayout $layout,
        public ?string $recipe,
        public array $members,
        public array $tables,
        public array $variances,
    ) {}

    /** @param array<string, mixed> $input */
    public static function from(array $input, Scope $scope): self
    {
        $validator = Validator::make(['proposal' => $input], [
            'proposal' => 'required|array:layout,recipe,variances',
            'proposal.layout' => 'required_without:proposal.recipe|array:base,path,mounts,types,pageEntries',
            'proposal.layout.base' => ['required_with:proposal.layout', 'string', Rule::in((new BuiltInLayouts)->names())],
            'proposal.layout.path' => 'sometimes|required|string',
            'proposal.layout.pageEntries' => 'sometimes|nullable|array|list',
            'proposal.layout.mounts' => 'sometimes|array|list',
            'proposal.layout.mounts.*' => 'required|array:id,namespace,path',
            'proposal.layout.mounts.*.id' => 'required|string|distinct',
            'proposal.layout.mounts.*.namespace' => 'present|nullable|string',
            'proposal.layout.mounts.*.path' => 'required|string',
            'proposal.layout.types' => 'sometimes|array|list',
            'proposal.layout.types.*' => 'required|array:id,in,suffix,extension',
            'proposal.layout.types.*.id' => 'required|string|distinct',
            'proposal.layout.types.*.in' => 'required|string',
            'proposal.layout.types.*.suffix' => 'sometimes|string',
            'proposal.layout.types.*.extension' => ['sometimes', 'string', 'regex:/^\.[a-zA-Z0-9.]+$/D'],
            'proposal.recipe' => 'required_without:proposal.layout|array:name,members,tables',
            'proposal.recipe.name' => 'required_with:proposal.recipe|string',
            'proposal.recipe.members' => 'required_with:proposal.recipe|array|list|min:1',
            'proposal.recipe.members.*' => 'required|array:id,path,class,page,pattern',
            'proposal.recipe.members.*.id' => 'required|string|distinct',
            'proposal.recipe.members.*.path' => 'required|string',
            'proposal.recipe.members.*.pattern' => 'sometimes|required|string|prohibits:proposal.recipe.members.*.class,proposal.recipe.members.*.page',
            'proposal.recipe.members.*.class' => ['sometimes', 'string', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D'],
            'proposal.recipe.members.*.page' => ['sometimes', 'string', 'regex:~^[a-zA-Z0-9_./:\\-]+$~D'],
            'proposal.recipe.tables' => 'sometimes|array|list',
            'proposal.recipe.tables.*' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/D'],
            'proposal.variances' => 'sometimes|array|list',
            'proposal.variances.*' => 'required|array:member,path,mechanism',
            'proposal.variances.*.member' => 'required|string|distinct',
            'proposal.variances.*.path' => 'required|string',
            'proposal.variances.*.mechanism' => 'sometimes|required|string',
        ]);
        if ($validator->fails()) {
            throw new ModException('Supply a layout and/or recipe using the mod-fit schema; unknown keys and malformed definitions are refused.');
        }
        /** @var array{layout?: array{base: string, path?: string, pageEntries?: list<string>|null, mounts?: list<array{id: string, namespace: ?string, path: string}>, types?: list<array{id: string, in: string, suffix?: string, extension?: string}>}, recipe?: array{name: string, members: list<Member>, tables?: list<string>}, variances?: list<Variance>} $input */
        $layout = null;
        if (isset($input['layout'])) {
            $definition = $input['layout'];
            $builder = isset($definition['path']) ? Layout::fresh('fit')->extends($definition['base']) : Layout::fresh($definition['base']);
            if (array_key_exists('pageEntries', $definition)) {
                try {
                    $builder->frontend(pageEntries: PageEntries::validate($definition['pageEntries'], 'mod-fit'));
                } catch (InvalidArgumentException $exception) {
                    throw new ModException($exception->getMessage());
                }
            }
            if (isset($definition['path'])) {
                $builder->path(self::path($definition['path'], $scope));
            }
            foreach ($definition['mounts'] ?? [] as $mount) {
                $builder->mounts($mount['id'], $mount['namespace'], self::path($mount['path'], $scope));
            }
            foreach ($definition['types'] ?? [] as $type) {
                $in = $type['in'];
                self::path(str_contains($in, ':') ? explode(':', $in, 2)[1] : $in, $scope);
                $builder->generates($type['id'], in: $in, suffix: $type['suffix'] ?? null,
                    using: isset($type['extension']) ? fn (FileType $fileType) => $fileType->extension($type['extension']) : null);
            }
            $layout = $builder->compiled();
            foreach ($layout->roots() as $root) {
                self::path($root->path, $scope);
            }
        }
        $members = $input['recipe']['members'] ?? [];
        foreach ($members as &$member) {
            if (isset($member['pattern'])) {
                if ($layout === null || ! in_array($member['path'], ['pages', 'components', 'views'], true)
                    || $layout->frontend()[$member['path']] === null) {
                    throw new ModException('Folder members require a proposed layout and a declared frontend path key: pages, components or views.');
                }
                $member['pattern'] = self::path($member['pattern'], $scope);
                if (preg_match('~^(?:[a-zA-Z0-9_*\-]+|\{\w+\})(?:/(?:[a-zA-Z0-9_*\-]+|\{\w+\}))*$~D', $member['pattern']) !== 1) {
                    throw new ModException('Folder patterns accept literal segments, {name}-style placeholders, * and **.');
                }
                self::path($layout->frontend()[$member['path']].'/'.$member['pattern'], $scope);
            } else {
                $member['path'] = self::path($member['path'], $scope);
            }
        }
        unset($member);
        $variances = $input['variances'] ?? [];
        foreach ($variances as &$variance) {
            $variance['path'] = self::path($variance['path'], $scope);
            if (! in_array($variance['member'], array_column($members, 'id'), true)) {
                throw new ModException('A variance must name a proposed recipe member.');
            }
            foreach ($members as $member) {
                if ($member['id'] === $variance['member'] && isset($member['pattern'])) {
                    throw new ModException('Folder members require a concrete path and pattern, not single-file variance evidence.');
                }
            }
        }
        unset($variance);

        return new self($layout, $input['recipe']['name'] ?? null, $members, $input['recipe']['tables'] ?? [], $variances);
    }

    private static function path(string $path, Scope $scope): string
    {
        $path = Path::normalize($path);
        if (Path::relative('', $path) === null || $path === '' || str_contains($path, ':') || str_contains($path, "\0")
            || $scope->excluded($path) || in_array('vendor', explode('/', strtolower($path)), true)) {
            throw new ModException('mod-fit paths must stay inside base_path(); outside paths, traversal, vendor, secrets and runtime data are refused.');
        }

        return $path;
    }
}
