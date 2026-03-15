<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Services;

use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCameraEvent;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VideoCameraService
{
    public function __construct(
        private readonly AccessController $accessController
    ) {
    }

    public function getProjectDashboard(Project $project, User $user): array
    {
        $organizationId = (int) $user->current_organization_id;
        $moduleActive = $this->accessController->hasModuleAccess($organizationId, 'video-monitoring');
        $permissions = $this->resolvePermissions($project, $user);
        $cameras = VideoCamera::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->orderByDesc('updated_at')
            ->get();

        $recentEvents = VideoCameraEvent::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return [
            'module' => [
                'slug' => 'video-monitoring',
                'is_active' => $moduleActive,
                'requires_activation' => !$moduleActive,
                'limits' => [
                    'max_cameras' => $moduleActive ? null : 1,
                    'max_live_viewers' => $moduleActive ? null : 1,
                ],
            ],
            'capabilities' => $permissions,
            'stats' => [
                'total_cameras' => $cameras->count(),
                'online_cameras' => $cameras->where('status', 'online')->count(),
                'offline_cameras' => $cameras->where('status', 'offline')->count(),
                'with_live' => $cameras->filter(fn (VideoCamera $camera) => !empty($camera->playback_url))->count(),
            ],
            'cameras' => $cameras->map(fn (VideoCamera $camera) => $this->transformCamera($camera))->values()->all(),
            'recent_events' => $recentEvents->map(fn (VideoCameraEvent $event) => [
                'id' => $event->id,
                'camera_id' => $event->camera_id,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'message' => $event->message,
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function create(Project $project, array $payload, User $user): array
    {
        $this->ensureManagePermission($project, $user, 'video_monitoring.connect');

        return DB::transaction(function () use ($project, $payload, $user) {
            $camera = new VideoCamera();
            $camera->fill($this->preparePayload($project, $payload, $user));
            $camera->status = 'pending';
            $camera->status_message = trans_message('video_monitoring.status.pending');
            $camera->save();

            $this->registerEvent($camera, 'camera.created', 'info', trans_message('video_monitoring.created'));
            $probe = $this->probeCameraConnection($this->prepareProbePayload($camera->toArray()));
            $this->syncCameraStatus($camera, $probe);

            return $this->transformCamera($camera->fresh());
        });
    }

    public function update(Project $project, VideoCamera $camera, array $payload, User $user): array
    {
        $this->ensureCameraBelongsToProject($project, $camera);
        $this->ensureManagePermission($project, $user, 'video_monitoring.edit');

        return DB::transaction(function () use ($project, $camera, $payload, $user) {
            $mergedPayload = array_merge(
                $camera->only([
                    'created_by',
                    'name',
                    'zone',
                    'source_type',
                    'source_url',
                    'playback_url',
                    'username',
                    'host',
                    'port',
                    'stream_path',
                    'transport_protocol',
                    'is_enabled',
                    'settings',
                ]),
                $payload
            );

            if (!array_key_exists('password', $payload) || blank($payload['password'])) {
                $mergedPayload['password'] = $camera->password;
            }

            $camera->fill($this->preparePayload($project, $mergedPayload, $user, false));
            $camera->save();

            $this->registerEvent($camera, 'camera.updated', 'info', trans_message('video_monitoring.updated'));
            $probe = $this->probeCameraConnection($this->prepareProbePayload($camera->toArray()));
            $this->syncCameraStatus($camera, $probe);

            return $this->transformCamera($camera->fresh());
        });
    }

    public function delete(Project $project, VideoCamera $camera, User $user): void
    {
        $this->ensureCameraBelongsToProject($project, $camera);
        $this->ensureManagePermission($project, $user, 'video_monitoring.delete');

        DB::transaction(function () use ($camera) {
            $this->registerEvent($camera, 'camera.deleted', 'warning', trans_message('video_monitoring.deleted'));
            $camera->delete();
        });
    }

    public function check(Project $project, array $payload, User $user): array
    {
        $this->ensureManagePermission($project, $user, 'video_monitoring.connect');

        return $this->probeCameraConnection($payload);
    }

    private function preparePayload(Project $project, array $payload, User $user, bool $isCreate = true): array
    {
        $sourceUrl = $this->resolveSourceUrl($payload);

        return [
            'organization_id' => (int) $user->current_organization_id,
            'project_id' => $project->id,
            'created_by' => $isCreate ? $user->id : Arr::get($payload, 'created_by'),
            'updated_by' => $user->id,
            'name' => Arr::get($payload, 'name'),
            'zone' => Arr::get($payload, 'zone'),
            'source_type' => Arr::get($payload, 'source_type', 'rtsp'),
            'source_url' => $sourceUrl,
            'playback_url' => Arr::get($payload, 'playback_url'),
            'username' => Arr::get($payload, 'username'),
            'password' => Arr::get($payload, 'password'),
            'host' => Arr::get($payload, 'host'),
            'port' => Arr::get($payload, 'port'),
            'stream_path' => Arr::get($payload, 'stream_path'),
            'transport_protocol' => Arr::get($payload, 'transport_protocol', 'tcp'),
            'is_enabled' => Arr::get($payload, 'is_enabled', true),
            'settings' => Arr::get($payload, 'settings', []),
        ];
    }

    private function prepareProbePayload(array $payload): array
    {
        return [
            'source_url' => Arr::get($payload, 'source_url'),
            'host' => Arr::get($payload, 'host'),
            'port' => Arr::get($payload, 'port'),
            'stream_path' => Arr::get($payload, 'stream_path'),
            'transport_protocol' => Arr::get($payload, 'transport_protocol'),
            'source_type' => Arr::get($payload, 'source_type'),
            'playback_url' => Arr::get($payload, 'playback_url'),
        ];
    }

    private function resolveSourceUrl(array $payload): string
    {
        $sourceUrl = trim((string) Arr::get($payload, 'source_url', ''));

        if ($sourceUrl !== '') {
            return $sourceUrl;
        }

        $host = trim((string) Arr::get($payload, 'host', ''));
        $streamPath = trim((string) Arr::get($payload, 'stream_path', ''));
        $sourceType = Arr::get($payload, 'source_type', 'rtsp');
        $port = Arr::get($payload, 'port');

        if ($host === '' || $streamPath === '') {
            throw new RuntimeException(trans_message('video_monitoring.source_required'));
        }

        $scheme = in_array($sourceType, ['cloud'], true) ? 'https' : 'rtsp';
        $portPart = $port ? ':' . $port : '';
        $path = str_starts_with($streamPath, '/') ? $streamPath : '/' . $streamPath;

        return sprintf('%s://%s%s%s', $scheme, $host, $portPart, $path);
    }

    private function probeCameraConnection(array $payload): array
    {
        $sourceUrl = $this->resolveSourceUrl($payload);
        $parsed = parse_url($sourceUrl);

        if (!is_array($parsed) || empty($parsed['host'])) {
            throw new RuntimeException(trans_message('video_monitoring.invalid_source'));
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? 'rtsp'));
        $host = (string) $parsed['host'];
        $port = (int) ($parsed['port'] ?? $this->defaultPort($scheme));
        $checkedAt = now();

        try {
            if (in_array($scheme, ['http', 'https'], true)) {
                Http::timeout(4)->head($sourceUrl)->throw();
            } else {
                $connection = @stream_socket_client(
                    sprintf('tcp://%s:%d', $host, $port),
                    $errorCode,
                    $errorMessage,
                    4
                );

                if (!is_resource($connection)) {
                    throw new RuntimeException($errorMessage ?: trans_message('video_monitoring.connection_failed'));
                }

                fclose($connection);
            }

            return [
                'is_online' => true,
                'status' => 'online',
                'message' => trans_message('video_monitoring.connection_success'),
                'checked_at' => $checkedAt->toIso8601String(),
                'resolved_source_url' => $sourceUrl,
                'resolved_playback_url' => Arr::get($payload, 'playback_url'),
            ];
        } catch (\Throwable $exception) {
            return [
                'is_online' => false,
                'status' => 'offline',
                'message' => $exception->getMessage() ?: trans_message('video_monitoring.connection_failed'),
                'checked_at' => $checkedAt->toIso8601String(),
                'resolved_source_url' => $sourceUrl,
                'resolved_playback_url' => Arr::get($payload, 'playback_url'),
            ];
        }
    }

    private function syncCameraStatus(VideoCamera $camera, array $probe): void
    {
        $camera->status = $probe['status'];
        $camera->status_message = $probe['message'];
        $camera->last_checked_at = Carbon::parse($probe['checked_at']);

        if (($probe['is_online'] ?? false) === true) {
            $camera->last_online_at = Carbon::parse($probe['checked_at']);
        }

        $camera->save();

        $this->registerEvent(
            $camera,
            ($probe['is_online'] ?? false) ? 'camera.online' : 'camera.offline',
            ($probe['is_online'] ?? false) ? 'info' : 'warning',
            $probe['message']
        );
    }

    private function registerEvent(VideoCamera $camera, string $eventType, string $severity, string $message): void
    {
        VideoCameraEvent::create([
            'camera_id' => $camera->id,
            'organization_id' => $camera->organization_id,
            'project_id' => $camera->project_id,
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'payload' => [],
            'occurred_at' => now(),
        ]);
    }

    private function ensureManagePermission(Project $project, User $user, string $permission): void
    {
        if (!$user->can($permission, $this->buildPermissionContext($project, $user))) {
            throw new RuntimeException(trans_message('video_monitoring.access_denied'));
        }
    }

    private function ensureCameraBelongsToProject(Project $project, VideoCamera $camera): void
    {
        if ($camera->project_id !== $project->id) {
            throw new RuntimeException(trans_message('video_monitoring.not_found'));
        }
    }

    private function resolvePermissions(Project $project, User $user): array
    {
        $context = $this->buildPermissionContext($project, $user);

        return [
            'can_view' => $user->can('video_monitoring.view', $context),
            'can_manage' => $user->can('video_monitoring.edit', $context),
            'can_connect' => $user->can('video_monitoring.connect', $context),
            'can_delete' => $user->can('video_monitoring.delete', $context),
            'can_watch_live' => $user->can('video_monitoring.watch_live', $context),
            'can_view_events' => $user->can('video_monitoring.events.view', $context),
        ];
    }

    private function buildPermissionContext(Project $project, User $user): array
    {
        AuthorizationContext::getProjectContext($project->id, (int) $user->current_organization_id);

        return [
            'organization_id' => (int) $user->current_organization_id,
            'project_id' => $project->id,
        ];
    }

    private function transformCamera(VideoCamera $camera): array
    {
        return [
            'id' => $camera->id,
            'name' => $camera->name,
            'zone' => $camera->zone,
            'source_type' => $camera->source_type,
            'source_url' => $camera->source_url,
            'source_url_masked' => $camera->masked_source_url,
            'playback_url' => $camera->playback_url,
            'username' => $camera->username,
            'host' => $camera->host,
            'port' => $camera->port,
            'stream_path' => $camera->stream_path,
            'status' => $camera->status,
            'status_message' => $camera->status_message,
            'transport_protocol' => $camera->transport_protocol,
            'is_enabled' => $camera->is_enabled,
            'has_credentials' => !empty($camera->username) || !empty($camera->password),
            'last_checked_at' => optional($camera->last_checked_at)->toIso8601String(),
            'last_online_at' => optional($camera->last_online_at)->toIso8601String(),
            'created_at' => optional($camera->created_at)->toIso8601String(),
            'updated_at' => optional($camera->updated_at)->toIso8601String(),
            'settings' => $camera->settings ?? [],
        ];
    }

    private function defaultPort(string $scheme): int
    {
        return match ($scheme) {
            'https' => 443,
            'http' => 80,
            'rtsps' => 322,
            default => 554,
        };
    }
}
