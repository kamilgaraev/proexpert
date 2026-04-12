<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeDocument;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Brigades\UpdateBrigadeDocumentStatusRequest;
use App\Http\Requests\Api\V1\Admin\Brigades\UpdateBrigadeVerificationRequest;
use App\Http\Resources\Brigades\BrigadeProfileResource;
use App\Http\Resources\Brigades\BrigadeDocumentResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrigadeCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BrigadeProfile::query()->with(['specializations', 'members', 'documents']);

        if ($request->filled('status')) {
            $query->where('verification_status', $request->string('status')->toString());
        } elseif ($request->boolean('only_public')) {
            $query->where('verification_status', BrigadeStatuses::PROFILE_APPROVED);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('contact_person', 'like', '%' . $search . '%');
            });
        }

        return AdminResponse::success(BrigadeProfileResource::collection($query->latest()->get()));
    }

    public function show(int $brigadeId): JsonResponse
    {
        $brigade = BrigadeProfile::query()
            ->with(['specializations', 'members', 'documents', 'assignments.project', 'invitations.project'])
            ->findOrFail($brigadeId);

        return AdminResponse::success(new BrigadeProfileResource($brigade));
    }

    public function updateStatus(UpdateBrigadeVerificationRequest $request, int $brigadeId): JsonResponse
    {
        $brigade = BrigadeProfile::query()->findOrFail($brigadeId);
        $settings = is_array($brigade->settings) ? $brigade->settings : [];
        $moderationNote = $request->input('moderation_note');

        if ($moderationNote !== null) {
            $settings['moderation_note'] = $moderationNote;
        }

        $brigade->update([
            'verification_status' => $request->string('verification_status')->toString(),
            'settings' => $settings,
        ]);

        return AdminResponse::success(
            new BrigadeProfileResource($brigade->load(['specializations', 'members', 'documents'])),
            trans_message('brigades.profile_updated')
        );
    }

    public function updateDocumentStatus(UpdateBrigadeDocumentStatusRequest $request, int $brigadeId, int $documentId): JsonResponse
    {
        $document = BrigadeDocument::query()
            ->where('brigade_id', $brigadeId)
            ->findOrFail($documentId);

        $document->update([
            'verification_status' => $request->string('verification_status')->toString(),
            'verification_notes' => $request->input('verification_notes'),
            'verified_at' => now(),
        ]);

        return AdminResponse::success(
            new BrigadeDocumentResource($document),
            trans_message('brigades.document_status_updated')
        );
    }
}
