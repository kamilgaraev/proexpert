<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services;

use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Models\EmployeeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RequestNotificationPublisher
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function enqueue(EmployeeRequest $request, int $userId, string $interface): void
    {
        $id = (string) Str::uuid();
        DB::table('workforce_request_notification_outbox')->insertOrIgnore([
            'id' => $id, 'organization_id' => $request->organization_id, 'request_id' => $request->id,
            'user_id' => $userId, 'revision' => $request->revision, 'status' => $request->status, 'interface' => $interface,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->dispatch($id));
    }

    public function recover(int $limit = 100): void
    {
        foreach (DB::table('workforce_request_notification_outbox')->whereNull('dispatched_at')->whereNull('discarded_at')->orderBy('created_at')->limit(max(1, min(1000, $limit)))->pluck('id') as $id) {
            $this->dispatch((string) $id);
        }
    }

    private function dispatch(string $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $event = DB::table('workforce_request_notification_outbox')->where('id', $id)->lockForUpdate()->first();
                if ($event === null || $event->dispatched_at !== null || $event->discarded_at !== null) {
                    return;
                }
                $user = User::query()->find($event->user_id);
                $request = EmployeeRequest::query()->where('organization_id', $event->organization_id)->find($event->request_id);
                if ($user === null || $request === null || ! $user->belongsToOrganization((int) $event->organization_id)) {
                    DB::table('workforce_request_notification_outbox')->where('id', $id)->update(['discarded_at' => now(), 'updated_at' => now()]);

                    return;
                }
                $this->notifications->sendWithId($id, $user, 'workforce.request.changed', [
                    'title' => trans_message('workforce.notifications.request_changed_title'),
                    'message' => trans_message('workforce.notifications.request_changed'),
                    'entity_type' => 'workforce_employee_request', 'entity_id' => $request->id,
                    'target_route' => $event->interface === 'mobile' ? 'workforce_self_service' : '/workforce',
                    'context' => ['request_id' => $request->id, 'status' => $event->status, 'revision' => $event->revision],
                ], 'system', 'normal', ['in_app'], (int) $event->organization_id,
                    $event->interface === 'mobile' ? 'workforce.self-service.view' : 'workforce.team-queue.view', [$event->interface]);
                DB::table('workforce_request_notification_outbox')->where('id', $id)->update(['dispatched_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $exception) {
            Log::warning('workforce.request_notification_pending', ['event_id' => $id, 'exception_class' => $exception::class]);
        }
    }
}
