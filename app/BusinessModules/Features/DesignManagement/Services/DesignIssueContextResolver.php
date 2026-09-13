<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewRound;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class DesignIssueContextResolver
{
    public function __construct(private readonly DesignModelSessionAccessService $sessionAccess) {}

    public function resolve(User $actor, int $organizationId, int $projectId, array $payload): array
    {
        $context = array_filter(array_intersect_key($payload, array_flip([
            'package_id', 'section_id', 'artifact_id', 'version_id', 'sheet_id', 'round_id',
        ])), static fn (mixed $value): bool => $value !== null);
        $context = array_map(static fn (mixed $id): int => (int) $id, $context);

        if (isset($context['sheet_id'])) {
            $sheet = $this->find(DesignDocumentSheet::class, $context['sheet_id'], $organizationId, $projectId);
            $this->mergeParents($context, $sheet, ['package_id', 'section_id', 'artifact_id', 'version_id']);
        }
        if (isset($context['version_id'])) {
            $version = $this->find(DesignArtifactVersion::class, $context['version_id'], $organizationId, $projectId);
            $this->mergeParents($context, $version, ['artifact_id']);
        }
        if (isset($context['artifact_id'])) {
            $artifact = $this->find(DesignArtifact::class, $context['artifact_id'], $organizationId, $projectId);
            $this->mergeParents($context, $artifact, ['package_id', 'section_id']);
        }
        if (isset($context['section_id'])) {
            $section = $this->find(DesignPackageSection::class, $context['section_id'], $organizationId, $projectId);
            $this->mergeParents($context, $section, ['package_id']);
        }
        if (isset($context['round_id'])) {
            $round = $this->find(DesignReviewRound::class, $context['round_id'], $organizationId, $projectId);
            $this->mergeParents($context, $round, ['package_id']);
        }
        if (isset($context['package_id'])) {
            $this->find(DesignPackage::class, $context['package_id'], $organizationId, $projectId);
        }

        if (isset($payload['bim_element_id'])) {
            $elementId = (string) $payload['bim_element_id'];
            if (! ctype_digit($elementId) || (int) $elementId < 1 || ! isset($context['version_id']) || ! DesignIfcModelElement::query()
                ->where('organization_id', $organizationId)
                ->where('version_id', $context['version_id'])
                ->where('express_id', $elementId)
                ->exists()) {
                throw $this->invalidTarget();
            }
            $context['bim_element_id'] = $elementId;
        }

        $hasRevision = isset($payload['model_set_revision_id']);
        $hasView = isset($payload['view_models']);
        if ($hasView && $hasRevision) {
            throw $this->invalidTarget();
        }
        $requestedElements = (array) ($payload['elements'] ?? []);
        if (count($requestedElements) > 100 || array_filter($requestedElements, static fn (mixed $element): bool => ! is_array($element)) !== []) {
            throw $this->invalidTarget();
        }
        if ($hasRevision || $hasView) {
            if ($hasView) {
                $viewModels = $this->viewModels($actor, $organizationId, $projectId, $payload['view_models']);
                $versionIds = array_column($viewModels, 'version_id');
                $context['view_models'] = $viewModels;
            } else {
                $revisionId = (int) $payload['model_set_revision_id'];
                $revision = DesignModelSetRevision::query()->with('modelSet')->find($revisionId);
                if (! $revision instanceof DesignModelSetRevision || $revision->modelSet === null
                    || (int) $revision->modelSet->organization_id !== $organizationId
                    || (int) $revision->modelSet->project_id !== $projectId
                    || ! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId)) {
                    throw $this->invalidTarget();
                }
                $versionIds = array_values(array_unique(array_map('intval', $revision->version_ids ?? [])));
                $context['model_set_revision_id'] = $revision->id;
            }
            if (isset($context['version_id']) && ! in_array($context['version_id'], $versionIds, true)) {
                throw $this->invalidTarget();
            }
            if ($versionIds === [] || DesignArtifactVersion::query()
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->whereIn('id', $versionIds)
                ->where('file_format', 'ifc')
                ->count() !== count($versionIds)) {
                throw $this->invalidTarget();
            }
            $elements = [];
            foreach ($requestedElements as $element) {
                $versionId = (int) ($element['version_id'] ?? 0);
                $elementId = (int) ($element['element_id'] ?? 0);
                if ($versionId < 1 || $elementId < 1 || ! in_array($versionId, $versionIds, true)
                    || ! DesignIfcModelElement::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('version_id', $versionId)->where('express_id', $elementId)->exists()) {
                    throw $this->invalidTarget();
                }
                $elements[] = ['version_id' => $versionId, 'element_id' => $elementId];
            }
            if ($elements !== []) {
                $context['elements'] = $elements;
            }
        } elseif ($requestedElements !== []) {
            if (! isset($context['version_id']) || ! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId)) {
                throw $this->invalidTarget();
            }
            $elements = [];
            foreach ($requestedElements as $element) {
                $versionId = (int) ($element['version_id'] ?? 0);
                $elementId = (int) ($element['element_id'] ?? 0);
                if ($versionId !== $context['version_id'] || $elementId < 1 || ! DesignIfcModelElement::query()
                    ->where('organization_id', $organizationId)->where('project_id', $projectId)
                    ->where('version_id', $versionId)->where('express_id', $elementId)->exists()) {
                    throw $this->invalidTarget();
                }
                $elements[] = ['version_id' => $versionId, 'element_id' => $elementId];
            }
            $context['elements'] = $elements;
        }

        if (isset($payload['point'])) {
            $point = $payload['point'];
            if (! is_array($point) || count($point) !== 3 || ! isset($point['x'], $point['y'], $point['z'])) {
                throw $this->invalidTarget();
            }
            foreach ($point as $coordinate) {
                if (! is_numeric($coordinate) || ! is_finite((float) $coordinate)) {
                    throw $this->invalidTarget();
                }
            }
            if (! $hasRevision && ! $hasView && (! isset($context['version_id']) || ! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId)
                || ! DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereKey($context['version_id'])->where('file_format', 'ifc')->exists())) {
                throw $this->invalidTarget();
            }
            $context['point'] = array_map('floatval', $point);
        }
        foreach (['camera'] as $key) {
            if (isset($payload[$key])) {
                $context[$key] = $payload[$key];
            }
        }
        if (($payload['annotations'] ?? null) !== null) {
            $annotations = $this->annotations($payload['annotations']);
            if ($annotations['strokes'] !== []) {
                if (! $this->cameraSnapshot($payload['camera'] ?? null)) {
                    throw $this->invalidTarget();
                }
                if (! $hasRevision && ! $hasView && (! isset($context['version_id'])
                    || ! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId)
                    || ! DesignArtifactVersion::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
                        ->whereKey($context['version_id'])->where('file_format', 'ifc')->exists())) {
                    throw $this->invalidTarget();
                }
            }
            $context['annotations'] = $annotations;
        }

        return $context;
    }

    private function annotations(mixed $annotations): array
    {
        if (! is_array($annotations) || ! $this->hasOnlyKeys($annotations, ['schema_version', 'width', 'height', 'strokes'])
            || ($annotations['schema_version'] ?? null) !== 1
            || ! $this->boundedNumber($annotations['width'] ?? null, 0, 16384) || (float) $annotations['width'] <= 0
            || ! $this->boundedNumber($annotations['height'] ?? null, 0, 16384) || (float) $annotations['height'] <= 0
            || (float) $annotations['width'] * (float) $annotations['height'] > 8_000_000
            || ! isset($annotations['strokes']) || ! is_array($annotations['strokes']) || ! array_is_list($annotations['strokes'])
            || count($annotations['strokes']) > 100) {
            throw $this->invalidTarget();
        }

        $strokes = [];
        $totalPoints = 0;
        foreach ($annotations['strokes'] as $stroke) {
            if (! is_array($stroke) || ! $this->hasOnlyKeys($stroke, ['kind', 'color', 'width', 'points'])
                || ! is_string($stroke['kind'] ?? null) || ! in_array($stroke['kind'], ['line', 'arrow', 'freehand'], true)
                || ! is_string($stroke['color'] ?? null) || preg_match('/^#[0-9a-fA-F]{6}$/', $stroke['color']) !== 1
                || ! $this->boundedNumber($stroke['width'] ?? null, 1, 20)
                || ! isset($stroke['points']) || ! is_array($stroke['points']) || ! array_is_list($stroke['points'])
                || count($stroke['points']) < 2 || count($stroke['points']) > 1000
                || (in_array($stroke['kind'], ['line', 'arrow'], true) && count($stroke['points']) !== 2)) {
                throw $this->invalidTarget();
            }
            $points = [];
            foreach ($stroke['points'] as $point) {
                if (! is_array($point) || ! $this->hasOnlyKeys($point, ['x', 'y'])
                    || ! $this->boundedNumber($point['x'] ?? null, 0, 1)
                    || ! $this->boundedNumber($point['y'] ?? null, 0, 1)) {
                    throw $this->invalidTarget();
                }
                $points[] = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
            }
            $totalPoints += count($points);
            if ($totalPoints > 10000) {
                throw $this->invalidTarget();
            }
            $strokes[] = [
                'kind' => $stroke['kind'],
                'color' => strtolower($stroke['color']),
                'width' => (float) $stroke['width'],
                'points' => $points,
            ];
        }

        return [
            'schema_version' => 1,
            'width' => (float) $annotations['width'],
            'height' => (float) $annotations['height'],
            'strokes' => $strokes,
        ];
    }

    private function cameraSnapshot(mixed $camera): bool
    {
        if (! is_array($camera)) {
            return false;
        }
        $serialized = json_encode($camera);
        if (! is_string($serialized) || strlen($serialized) > 8192) {
            return false;
        }
        $vectors = ['position', 'target', 'focalOffset', 'target0', 'position0', 'focalOffset0'];
        $numbers = [
            'minDistance', 'maxDistance', 'minZoom', 'maxZoom', 'minPolarAngle', 'maxPolarAngle',
            'minAzimuthAngle', 'maxAzimuthAngle', 'smoothTime', 'draggingSmoothTime', 'dollySpeed',
            'truckSpeed', 'zoom', 'zoom0',
        ];
        $booleans = ['enabled', 'dollyToCursor'];
        $allowed = [...$vectors, ...$numbers, ...$booleans];
        if (array_diff(array_keys($camera), $allowed) !== []) {
            return false;
        }
        foreach (['position', 'target'] as $key) {
            if (! isset($camera[$key]) || ! $this->vector($camera[$key])) {
                return false;
            }
        }
        foreach (['focalOffset', 'target0', 'position0', 'focalOffset0'] as $key) {
            if (array_key_exists($key, $camera) && ! $this->vector($camera[$key])) {
                return false;
            }
        }
        foreach ($numbers as $key) {
            if (array_key_exists($key, $camera) && ! $this->finiteNumber($camera[$key])) {
                return false;
            }
        }
        foreach ($booleans as $key) {
            if (array_key_exists($key, $camera) && ! is_bool($camera[$key])) {
                return false;
            }
        }

        return true;
    }

    private function vector(mixed $value): bool
    {
        if (! is_array($value) || array_keys($value) !== [0, 1, 2]) {
            return false;
        }
        foreach ($value as $coordinate) {
            if (! $this->finiteNumber($coordinate)) {
                return false;
            }
        }

        return true;
    }

    private function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function hasOnlyKeys(array $value, array $keys): bool
    {
        sort($keys);
        $actual = array_keys($value);
        sort($actual);

        return $actual === $keys;
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= $minimum && (float) $value <= $maximum;
    }

    private function viewModels(User $actor, int $organizationId, int $projectId, mixed $models): array
    {
        if (! is_array($models) || ! array_is_list($models) || count($models) < 1 || count($models) > 100
            || ! $this->sessionAccess->canAccessProject($actor, $organizationId, $projectId)) {
            throw $this->invalidTarget();
        }
        $result = [];
        foreach ($models as $model) {
            if (! is_array($model) || ! isset($model['version_id']) || ! is_numeric($model['version_id'])
                || (int) $model['version_id'] < 1 || (float) $model['version_id'] !== (float) (int) $model['version_id']) {
                throw $this->invalidTarget();
            }
            $versionId = (int) $model['version_id'];
            if (isset($result[$versionId])) {
                throw $this->invalidTarget();
            }
            $transform = $model['transform'] ?? null;
            if (! is_array($transform) || ! isset($transform['shift'], $transform['rotation'])
                || ! is_array($transform['shift']) || ! array_is_list($transform['shift']) || count($transform['shift']) !== 3) {
                throw $this->invalidTarget();
            }
            foreach ([...$transform['shift'], $transform['rotation']] as $number) {
                if (! is_numeric($number) || ! is_finite((float) $number)) {
                    throw $this->invalidTarget();
                }
            }
            $version = $this->find(DesignArtifactVersion::class, $versionId, $organizationId, $projectId);
            $artifact = $this->find(DesignArtifact::class, (int) $version->getAttribute('artifact_id'), $organizationId, $projectId);
            $this->find(DesignPackage::class, (int) $artifact->getAttribute('package_id'), $organizationId, $projectId);
            $result[$versionId] = ['version_id' => $versionId, 'transform' => [
                'shift' => array_map('floatval', $transform['shift']), 'rotation' => (float) $transform['rotation'],
            ]];
        }

        return array_values($result);
    }

    private function mergeParents(array &$context, Model $model, array $keys): void
    {
        foreach ($keys as $key) {
            $parentId = $model->getAttribute($key);
            if (isset($context[$key]) && ($parentId === null || $context[$key] !== (int) $parentId)) {
                throw $this->invalidTarget();
            }
            if ($parentId !== null) {
                $context[$key] = (int) $parentId;
            }
        }
    }

    private function find(string $modelClass, int $id, int $organizationId, int $projectId): Model
    {
        $model = $modelClass::query()->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->find($id);
        if (! $model instanceof Model) {
            throw $this->invalidTarget();
        }

        return $model;
    }

    private function invalidTarget(): DomainException
    {
        return new DomainException(trans_message('design_issues.errors.target_not_found'));
    }
}
