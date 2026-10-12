<?php

namespace Tey\Mod\Rename;

use Tey\Mod\Artifact\ArtifactKind;
use Tey\Mod\Artifact\FileIdentity;
use Tey\Mod\Artifact\IdentityShape;
use Tey\Mod\Artifact\NamePolicy;
use Tey\Mod\Artifact\ResolvedArtifact;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Generation\PlainFile\Identity;
use Tey\Mod\Layout\CompiledLayout;
use Tey\Mod\Plans\Plan;
use Tey\Mod\Scaffolds\Member;
use Tey\Mod\Support\Path;

/** @internal Expand folder ownership into file identities, but retain one subtree move. */
final readonly class FolderPlanner
{
    public function __construct(private string $basePath) {}

    /**
     * @param  array<string, array{artifact: ResolvedArtifact, member: Member, identity: array<string, string>, layout: CompiledLayout}>  $old
     * @param  array<string, array{artifact: ResolvedArtifact, member: Member, identity: array<string, string>, layout: CompiledLayout}>  $new
     * @param  array<string, InputFile>  $files
     */
    public function expand(array &$old, array &$new, Plan $plan, array $files): void
    {
        $folders = array_filter($old, static fn (array $entry): bool => $entry['member']->folder);
        $targets = array_intersect_key($new, $folders);
        $guard = new PathGuard($this->basePath);
        foreach ($folders as $alias => $entry) {
            $before = $entry['artifact'];
            $target = $targets[$alias];
            $after = $target['artifact'];
            $from = $before->path();
            $to = $after->path();
            $plan->folders[] = ['alias' => $alias, 'type' => $entry['member']->fileType, 'path' => $from];
            foreach ([$from, $to] as $path) {
                if (($problem = $guard->problem($path)) !== null) {
                    throw GenerationRefused::because($problem);
                }
            }
            foreach ($folders as $other => $candidate) {
                if ($other !== $alias && (Path::relative($from, $candidate['artifact']->path()) !== null || Path::relative($to, $targets[$other]['artifact']->path()) !== null)) {
                    throw GenerationRefused::because('mod:rename folder ownership or destinations overlap. Give every subtree one owner. Nothing was written.');
                }
            }
            foreach ($new as $key => $member) {
                if (! $member['member']->folder && Path::relative($from, $old[$key]['artifact']->path()) === null && (Path::relative($to, $member['artifact']->path()) !== null || Path::relative($member['artifact']->path(), $to) !== null)) {
                    throw GenerationRefused::because("mod:rename member {$key} conflicts with a folder destination. Give every subtree one owner. Nothing was written.");
                }
            }
            if ($from !== $to && (Path::relative($from, $to) !== null || Path::relative($to, $from) !== null)) {
                throw GenerationRefused::because('mod:rename folder source and destination overlap. Choose a separate subtree. Nothing was written.');
            }
            if (! is_dir(Path::resolve($this->basePath, $from))) {
                throw GenerationRefused::because("mod:rename source folder {$from} is missing. Nothing was written.");
            }
            if ($from !== $to && file_exists(Path::resolve($this->basePath, $to))) {
                throw GenerationRefused::because("mod:rename destination {$to} already exists. Choose another target. Nothing was written.");
            }
            $tree = Snapshot::tree($this->basePath, $from);
            if ($tree['files'] === []) {
                throw GenerationRefused::because("mod:rename source folder {$from} has no tracked files. Nothing was written.");
            }
            unset($old[$alias], $new[$alias]);
            foreach ($tree['files'] as $path) {
                if (! isset($files[$path])) {
                    throw GenerationRefused::because("mod:rename folder content {$path} is ignored or outside the owned scan roots. Track it before retrying. Nothing was written.");
                }
                $destination = Path::join($to, (string) Path::relative($from, $path));
                $declared = array_keys(array_filter($old, static fn (array $member): bool => $member['artifact']->path() === $path));
                if (count($declared) > 1) {
                    throw GenerationRefused::because("mod:rename duplicate source {$path}. Nothing was written.");
                }
                if ($declared !== []) {
                    $key = $declared[0];
                    if ($new[$key]['artifact']->path() !== $destination || $old[$key]['member']->existing === 'keep') {
                        throw GenerationRefused::because("mod:rename member {$key} conflicts with its owning folder. Nothing was written.");
                    }
                } else {
                    $key = $alias.'/'.$path;
                    $extension = str_ends_with($path, '.blade.php') ? '.blade.php' : '.'.pathinfo($path, PATHINFO_EXTENSION);
                    $type = $entry['member']->fileType === 'pages' && in_array($extension, ['.vue', '.tsx', '.jsx', '.svelte', '.js', '.ts'], true) ? 'page' : 'plain';
                    $kind = new ArtifactKind($type, IdentityShape::File, NamePolicy::asGiven(), extension: $extension);
                    $sourceArtifact = new ResolvedArtifact($kind, $before->context, basename($path), new FileIdentity($path));
                    $targetArtifact = new ResolvedArtifact($kind, $after->context, basename($destination), new FileIdentity($destination));
                    $old[$key] = ['artifact' => $sourceArtifact, 'member' => new Member($type), 'identity' => Identity::forms($sourceArtifact, $entry['layout']), 'layout' => $entry['layout']];
                    $new[$key] = ['artifact' => $targetArtifact, 'member' => new Member($type), 'identity' => Identity::forms($targetArtifact, $target['layout']), 'layout' => $target['layout']];
                }
                if ($path !== $destination) {
                    $plan->folderContents[] = ['alias' => $key, 'type' => $old[$key]['artifact']->fileType->id, 'from' => $path, 'to' => $destination, 'old_class' => null, 'new_class' => null];
                }
            }
            if ($from !== $to) {
                $plan->rename['moves'][] = ['alias' => $alias, 'type' => 'folder', 'from' => $from, 'to' => $to, 'old_class' => null, 'new_class' => null];
            }
        }
    }
}
