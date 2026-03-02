<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ApprovalWorkflowService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ApprovePaymentRequestTool implements AIToolInterface
{
    protected ApprovalWorkflowService $approvalService;

    public function __construct(ApprovalWorkflowService $approvalService)
    {
        $this->approvalService = $approvalService;
    }

    public function getName(): string
    {
        return 'approve_payment_request';
    }

    public function getDescription(): string
    {
        return 'Одобряет (согласовывает) заявку на оплату или платежный документ по его ID. Это действие может выполнять только пользователь с соответствующими правами.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_document_id' => [
                    'type' => 'integer',
                    'description' => 'ID платежного документа или заявки на оплату'
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Необязательный комментарий к одобрению'
                ]
            ],
            'required' => ['payment_document_id']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Пользователь не аутентифицирован'
            ];
        }

        $documentId = $arguments['payment_document_id'];
        $comment = $arguments['comment'] ?? null;

        $document = PaymentDocument::where('id', $documentId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$document) {
            return [
                'status' => 'error',
                'message' => "Платежный документ с ID {$documentId} не найден в вашей организации."
            ];
        }

        try {
            $success = $this->approvalService->approveByUser($document, $user->id, $comment);

            if ($success) {
                return [
                    'status' => 'success',
                    'message' => "Документ №{$document->document_number} на сумму {$document->amount} успешно одобрен.",
                    'document_id' => $document->id
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Не удалось одобрить документ. Возможно, вы уже его одобряли или у вас недостаточно прав.'
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (ApprovePaymentRequestTool): ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Ошибка при одобрении документа: ' . $e->getMessage()
            ];
        }
    }
}
