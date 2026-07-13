<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

enum EstimateGenerationAction: string
{
    case UploadDocuments = 'upload_documents';
    case StartDocumentProcessing = 'start_document_processing';
    case ConfirmInput = 'confirm_input';
    case Generate = 'generate';
    case Review = 'review';
    case Apply = 'apply';
    case Export = 'export';
    case Retry = 'retry';
    case Cancel = 'cancel';
    case Archive = 'archive';
}
