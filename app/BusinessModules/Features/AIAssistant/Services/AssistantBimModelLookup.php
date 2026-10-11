<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AssistantBimModelLookup
{
    public function apply(Builder $versions, ?Builder $artifacts, ?Builder $packages, ?Builder $projects, ?string $term): void
    {
        $term = preg_replace('/\.ifc$/iu', '', trim($term ?? '')) ?? '';
        if ($term === '') {
            return;
        }
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
        foreach ($tokens as $token) {
            $stem = mb_strlen($token) > 4 && preg_match('/^[а-я]+$/u', $token) === 1
                ? (preg_replace('/(?:ого|его|ому|ему|ыми|ими|иях|ая|яя|ой|ую|юю|ые|ых|ие|ий|ии|ям|ей|ам|ов|ах|ях|а|я|ы|и|у|е)$/u', '', $token) ?? $token) : $token;
            $versions->where(function (Builder $names) use ($stem, $artifacts, $packages, $projects): void {
                $names->where(fn (Builder $own) => $this->text($own, ['design_artifact_versions.title', 'design_artifact_versions.source_original_name'], $stem));
                if ($artifacts !== null) {
                    $artifact = (clone $artifacts)->select('design_artifacts.id')
                        ->whereColumn('design_artifacts.id', 'design_artifact_versions.artifact_id')
                        ->whereColumn('design_artifacts.project_id', 'design_artifact_versions.project_id')
                        ->where(function (Builder $parent) use ($stem, $packages): void {
                            $parent->where(fn (Builder $title) => $this->text($title, ['design_artifacts.title'], $stem));
                            if ($packages !== null) {
                                $package = (clone $packages)->select('design_packages.id')
                                    ->whereColumn('design_packages.id', 'design_artifacts.package_id')
                                    ->whereColumn('design_packages.project_id', 'design_artifacts.project_id');
                                $this->text($package, ['design_packages.title'], $stem);
                                $parent->orWhereExists($package->toBase());
                            }
                        });
                    $names->orWhereExists($artifact->toBase());
                }
                if ($projects !== null) {
                    $project = (clone $projects)->select('projects.id')->whereColumn('projects.id', 'design_artifact_versions.project_id');
                    $this->text($project, ['projects.name'], $stem);
                    $names->orWhereExists($project->toBase());
                }
            });
        }
    }

    private function text(Builder $query, array $columns, string $stem): void
    {
        $terms = array_unique([$stem, mb_strtolower(Str::transliterate($stem))]);
        $query->where(function (Builder $search) use ($columns, $terms): void {
            foreach ($columns as $column) {
                $normalized = "replace(lower({$column}), 'ё', 'е')";
                $transliterated = $normalized;
                foreach (preg_split('//u', 'абвгдежзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    $ascii = str_replace("'", "''", mb_strtolower(Str::transliterate($character)));
                    $transliterated = "replace({$transliterated}, '{$character}', '{$ascii}')";
                }
                foreach ($terms as $term) {
                    $pattern = '%'.addcslashes($term, '%_\\').'%';
                    $search->orWhereRaw("{$normalized} LIKE ?", [$pattern])
                        ->orWhereRaw("{$transliterated} LIKE ?", [$pattern]);
                }
            }
        });
    }
}
