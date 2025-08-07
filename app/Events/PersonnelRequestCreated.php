<?php

namespace App\Events;

use App\Models\SiteRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PersonnelRequestCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SiteRequest $siteRequest;

    /**
     * Create a new event instance.
     */
    public function __construct(SiteRequest $siteRequest)
    {
        $this->siteRequest = $siteRequest;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.' . $this->siteRequest->organization_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'personnel.request.created';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'site_request_id' => $this->siteRequest->id,
            'project_id' => $this->siteRequest->project_id,
            'title' => $this->siteRequest->title,
            'personnel_type' => $this->siteRequest->personnel_type?->value,
            'personnel_count' => $this->siteRequest->personnel_count,
            'work_start_date' => $this->siteRequest->work_start_date?->format('Y-m-d'),
            'work_end_date' => $this->siteRequest->work_end_date?->format('Y-m-d'),
            'hourly_rate' => $this->siteRequest->hourly_rate,
            'author' => [
                'id' => $this->siteRequest->user->id,
                'name' => $this->siteRequest->user->name,
            ],
            'project' => [
                'id' => $this->siteRequest->project->id,
                'name' => $this->siteRequest->project->name,
            ],
            'created_at' => $this->siteRequest->created_at->toISOString(),
            'message' => "Новая заявка на персонал: {$this->siteRequest->title}",
        ];
    }
}