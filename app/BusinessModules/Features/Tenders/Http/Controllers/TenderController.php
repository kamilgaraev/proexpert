<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Http\Controllers;

use App\BusinessModules\Features\Tenders\Exceptions\TenderWorkflowException;
use App\BusinessModules\Features\Tenders\Services\TenderRegistryService;
use App\BusinessModules\Features\Tenders\Services\TenderWorkflowService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class TenderController extends Controller
{
    public function __construct(
        private readonly TenderRegistryService $registry,
        private readonly TenderWorkflowService $workflow,
        private readonly AuthorizationService $authorization
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->registry->summary($this->organizationId($request), $this->canViewAmounts($request)));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.summary');
        }
    }

    public function references(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->registry->references($this->organizationId($request)));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.references');
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $canViewAmounts = $this->canViewAmounts($request);
            $paginator = $this->registry->paginate($organizationId, $request->all(), $this->perPage($request));
            $items = $paginator->getCollection()
                ->map(fn ($tender): array => $this->registry->serialize($tender, $canViewAmounts))
                ->values()
                ->all();

            return AdminResponse::paginated(
                $items,
                [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                null,
                200,
                $this->registry->summary($organizationId, $canViewAmounts)
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.index');
        }
    }

    public function show(Request $request, string $tenderId): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->registry->serialize(
                    $this->registry->find($this->organizationId($request), $tenderId),
                    $this->canViewAmounts($request),
                    true
                )
            );
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.show');
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $tender = $this->registry->create($this->organizationId($request), $this->validatedTenderPayload($request, true), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.created'), 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.store');
        }
    }

    public function update(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->registry->update($this->organizationId($request), $tenderId, $this->validatedTenderPayload($request, false), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.updated'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.update');
        }
    }

    public function archive(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->registry->archive($this->organizationId($request), $tenderId, $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.archived'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.archive');
        }
    }

    public function restore(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->registry->restore($this->organizationId($request), $tenderId, $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.restored'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.restore');
        }
    }

    public function analyze(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->workflow->analyze($this->registry->find($this->organizationId($request), $tenderId), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.analysis_started'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.workflow');
        }
    }

    public function decideGoNoGo(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->workflow->decideGoNoGo($this->registry->find($this->organizationId($request), $tenderId), $request->all(), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.decision_saved'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.workflow');
        }
    }

    public function submit(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->workflow->submit($this->registry->find($this->organizationId($request), $tenderId), $request->all(), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.submitted'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.workflow');
        }
    }

    public function recordResult(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->workflow->recordResult($this->registry->find($this->organizationId($request), $tenderId), $request->all(), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.result_saved'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.workflow');
        }
    }

    public function cancel(Request $request, string $tenderId): JsonResponse
    {
        try {
            $tender = $this->workflow->cancel($this->registry->find($this->organizationId($request), $tenderId), $request->all(), $this->actorId($request));

            return AdminResponse::success($this->registry->serialize($tender, $this->canViewAmounts($request), true), trans_message('tenders.messages.cancelled'));
        } catch (Throwable $e) {
            return $this->failure($e, 'tenders.errors.workflow');
        }
    }

    private function validatedTenderPayload(Request $request, bool $creating): array
    {
        $workflowLockedFields = [
            'status',
            'submitted_at',
            'submission_confirmation_file_id',
            'submission_confirmation_url',
            'final_bid_amount',
            'final_bid_amount_missing_reason',
            'winner_amount',
            'result_published_at',
            'go_no_go_decision',
            'go_no_go_reason',
            'lost_reason',
            'cancel_reason',
            'winner_name',
        ];
        $rules = [
            'source_id' => ['nullable', 'uuid'],
            'customer_company_id' => ['nullable', 'uuid'],
            'customer_contact_id' => ['nullable', 'uuid'],
            'owner_user_id' => ['nullable', 'integer'],
            'crm_deal_id' => ['nullable', 'uuid'],
            'commercial_proposal_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'number' => ['nullable', 'string', 'max:64'],
            'external_number' => ['nullable', 'string', 'max:128'],
            'external_url' => ['nullable', 'url', 'max:4000'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string', 'max:1000'],
            'customer_inn' => ['nullable', 'string', 'max:32'],
            'customer_kpp' => ['nullable', 'string', 'max:32'],
            'customer_ogrn' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'risk_level' => ['nullable', 'in:low,medium,high,critical'],
            'initial_max_price' => ['nullable', 'numeric', 'min:0'],
            'budget_missing_reason' => ['nullable', 'string', 'max:2000'],
            'expected_bid_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'published_at' => ['nullable', 'date'],
            'questions_deadline_at' => ['nullable', 'date'],
            'submission_deadline_at' => ['nullable', 'date'],
            'opening_at' => ['nullable', 'date'],
            'auction_at' => ['nullable', 'date'],
            'result_expected_at' => ['nullable', 'date'],
            'requirements_summary' => ['nullable', 'string', 'max:5000'],
            'analysis_summary' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'array'],
            'evaluation_criteria' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];

        foreach ($workflowLockedFields as $field) {
            $rules[$field] = ['prohibited'];
        }

        $validator = Validator::make($request->all(), $rules, [
            'title.required' => trans_message('tenders.validation.title_required'),
            'external_url.url' => trans_message('tenders.validation.url_invalid'),
            'status.prohibited' => trans_message('tenders.validation.status_direct_update'),
            ...$this->workflowLockedMessages($workflowLockedFields),
        ]);

        if ($creating) {
            $validator->after(function ($validator) use ($request): void {
                if ($this->empty($request->input('customer_company_id')) && $this->empty($request->input('customer_name'))) {
                    $validator->errors()->add('customer_name', trans_message('tenders.validation.customer_required'));
                }

                if ($this->empty($request->input('source_id')) && $this->empty($request->input('external_url'))) {
                    $validator->errors()->add('source_id', trans_message('tenders.validation.source_required'));
                }
            });
        }

        return $validator->validate();
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function actorId(Request $request): ?int
    {
        return $request->user()?->id;
    }

    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return $this->authorization->can($user, 'tenders.amounts.view', [
            'organization_id' => $this->organizationId($request),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    private function failure(Throwable $e, string $translationKey): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return AdminResponse::error($this->validationMessage($e, $translationKey), 422, $e->errors());
        }

        if ($e instanceof TenderWorkflowException) {
            return AdminResponse::error($e->getMessage(), $e->statusCode(), null, [
                'blockers' => $e->blockers(),
            ]);
        }

        if ($e instanceof ModelNotFoundException) {
            return AdminResponse::error(trans_message('tenders.errors.not_found'), 404);
        }

        Log::error($translationKey, [
            'user_id' => auth()->id(),
            'message' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message($translationKey), 500);
    }

    private function validationMessage(ValidationException $e, string $translationKey): string
    {
        foreach ($e->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return trans_message($translationKey);
    }

    private function empty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function workflowLockedMessages(array $fields): array
    {
        $messages = [];

        foreach ($fields as $field) {
            if ($field === 'status') {
                continue;
            }

            $messages[$field . '.prohibited'] = trans_message('tenders.validation.workflow_field_locked');
        }

        return $messages;
    }
}
