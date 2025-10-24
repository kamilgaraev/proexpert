<?php

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationAnalytics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalyticsService
{
    public function trackDelivery(Notification $notification, string $channel, bool $success, ?string $errorMessage = null): void
    {
        try {
            $analytics = NotificationAnalytics::where('notification_id', $notification->id)
                ->where('channel', $channel)
                ->first();

            if (!$analytics) {
                $analytics = NotificationAnalytics::create([
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'status' => $success ? 'sent' : 'failed',
                ]);
            }

            if ($success) {
                $analytics->updateStatus('sent');
            } else {
                $analytics->updateStatus('failed');
                $analytics->update(['error_message' => $errorMessage]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to track delivery', [
                'notification_id' => $notification->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function trackOpen(string $trackingId): bool
    {
        try {
            $analytics = NotificationAnalytics::where('tracking_id', $trackingId)->first();

            if (!$analytics) {
                Log::warning('Analytics not found for tracking', ['tracking_id' => $trackingId]);
                return false;
            }

            if (!$analytics->opened_at) {
                $analytics->updateStatus('opened');
                
                Log::info('Notification opened', [
                    'notification_id' => $analytics->notification_id,
                    'channel' => $analytics->channel,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to track open', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function trackClick(string $trackingId, string $url): bool
    {
        try {
            $analytics = NotificationAnalytics::where('tracking_id', $trackingId)->first();

            if (!$analytics) {
                Log::warning('Analytics not found for tracking', ['tracking_id' => $trackingId]);
                return false;
            }

            if (!$analytics->clicked_at) {
                $analytics->updateStatus('clicked');
            }

            $metadata = $analytics->metadata ?? [];
            $metadata['clicked_urls'] = $metadata['clicked_urls'] ?? [];
            $metadata['clicked_urls'][] = [
                'url' => $url,
                'timestamp' => now()->toIso8601String(),
            ];
            $analytics->update(['metadata' => $metadata]);

            Log::info('Notification link clicked', [
                'notification_id' => $analytics->notification_id,
                'url' => $url,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to track click', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getStats(array $filters = []): array
    {
        $query = NotificationAnalytics::query();

        if (isset($filters['organization_id'])) {
            $query->whereHas('notification', function ($q) use ($filters) {
                $q->where('organization_id', $filters['organization_id']);
            });
        }

        if (isset($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date'])->toDateTimeString());
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to_date'])->toDateTimeString());
        }

        $total = $query->count();
        $sent = (clone $query)->where('status', 'sent')->count();
        $delivered = (clone $query)->where('status', 'delivered')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $opened = (clone $query)->whereNotNull('opened_at')->count();
        $clicked = (clone $query)->whereNotNull('clicked_at')->count();

        $deliveryRate = $total > 0 ? round(($sent + $delivered) / $total * 100, 2) : 0;
        $openRate = ($sent + $delivered) > 0 ? round($opened / ($sent + $delivered) * 100, 2) : 0;
        $clickRate = $opened > 0 ? round($clicked / $opened * 100, 2) : 0;

        return [
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'failed' => $failed,
            'opened' => $opened,
            'clicked' => $clicked,
            'delivery_rate' => $deliveryRate,
            'open_rate' => $openRate,
            'click_rate' => $clickRate,
        ];
    }

    public function getStatsByChannel(?int $organizationId = null): array
    {
        $query = NotificationAnalytics::query();

        if ($organizationId) {
            $query->whereHas('notification', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }

        $stats = $query->select([
            'channel',
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status IN ("sent", "delivered") THEN 1 ELSE 0 END) as successful'),
            DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed'),
            DB::raw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened'),
            DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked'),
        ])
        ->groupBy('channel')
        ->get()
        ->map(function ($stat) {
            $deliveryRate = $stat->total > 0 ? round($stat->successful / $stat->total * 100, 2) : 0;
            $openRate = $stat->successful > 0 ? round($stat->opened / $stat->successful * 100, 2) : 0;
            $clickRate = $stat->opened > 0 ? round($stat->clicked / $stat->opened * 100, 2) : 0;

            return [
                'channel' => $stat->channel,
                'total' => $stat->total,
                'successful' => $stat->successful,
                'failed' => $stat->failed,
                'opened' => $stat->opened,
                'clicked' => $stat->clicked,
                'delivery_rate' => $deliveryRate,
                'open_rate' => $openRate,
                'click_rate' => $clickRate,
            ];
        });

        return $stats->toArray();
    }
}

