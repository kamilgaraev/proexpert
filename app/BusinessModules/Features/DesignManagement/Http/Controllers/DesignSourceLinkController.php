<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignImpactReviewDecisionRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignSourceLinkRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\EndDesignSourceLinkRequest;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignImpactReviewResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignSourceLinkResource;
use App\BusinessModules\Features\DesignManagement\Services\DesignSourceLinkService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DesignSourceLinkController extends Controller
{
    public function __construct(private readonly DesignSourceLinkService $service) {}

    public function sourceLinks(Request $request, int $versionId): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => DesignSourceLinkResource::collection($this->service->linksForSource($actor, $this->organizationId($request), $versionId)), 'links_loaded');
    }

    public function targetLinks(Request $request, string $targetType, int $targetId): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => DesignSourceLinkResource::collection($this->service->linksForTarget($actor, $this->organizationId($request), $targetType, $targetId)), 'links_loaded');
    }

    public function sourceContext(Request $request, int $linkId): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => $this->service->sourceContext($actor, $this->organizationId($request), $linkId), 'links_loaded');
    }

    public function searchTargets(Request $request): JsonResponse
    {
        $validated = $request->validate(['project_id' => ['required', 'integer'], 'target_type' => ['required', 'string'], 'query' => ['required', 'string', 'min:2', 'max:120']]);

        return $this->respond(fn (User $actor): mixed => $this->service->searchTargets($actor, $this->organizationId($request), (int) $validated['project_id'], (string) $validated['target_type'], (string) $validated['query']), 'links_loaded');
    }

    public function store(StoreDesignSourceLinkRequest $request): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => new DesignSourceLinkResource($this->service->create($actor, $this->organizationId($request), $request->validated())), 'link_created', 201);
    }

    public function destroy(EndDesignSourceLinkRequest $request, int $linkId): JsonResponse
    {
        return $this->respond(function (User $actor) use ($request, $linkId): mixed {
            $this->service->delete($actor, $this->organizationId($request), $linkId, $request->string('reason')->toString(), $request->integer('expected_revision'));

            return null;
        }, 'link_deleted');
    }

    public function reviews(Request $request, int $versionId): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => DesignImpactReviewResource::collection($this->service->reviewsForSource($actor, $this->organizationId($request), $versionId)), 'reviews_loaded');
    }

    public function decide(StoreDesignImpactReviewDecisionRequest $request, int $reviewId): JsonResponse
    {
        return $this->respond(fn (User $actor): mixed => new DesignImpactReviewResource($this->service->decideReview($actor, $this->organizationId($request), $reviewId, $request->string('decision')->toString(), $request->string('reason')->toString(), $request->integer('expected_revision'), $request->filled('new_source_sheet_id') ? $request->integer('new_source_sheet_id') : null, $request->filled('new_source_element_id') ? $request->integer('new_source_element_id') : null)), 'review_decided');
    }

    public function replacementSheets(Request $request, int $reviewId): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return $this->respond(fn (User $actor): mixed => $this->service->replacementSheets($actor, $this->organizationId($request), $reviewId, $request->integer('page', 1)), 'links_loaded');
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function respond(callable $callback, string $message, int $status = 200): JsonResponse
    {
        try {
            $actor = request()->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('design_links.errors.forbidden'), 403);
            }

return AdminResponse::success($callback($actor), trans_message("design_links.messages.{$message}"), $status);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }
}
