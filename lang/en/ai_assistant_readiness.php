<?php

declare(strict_types=1);

return [
    'description' => 'Current authorized document text-search readiness: complete status counts, up to three examples per status and next actions. Null project means all authorized organization documents. Metadata only; no content summary or content completeness claim.',
    'scope_all' => 'Authorized organization document text-search readiness. Total: :total.',
    'scope_project' => 'Authorized project #:project document text-search readiness. Total: :total.',
    'window' => 'Shown :shown of :total documents in this status.',
    'queued' => 'Queued', 'queued_action' => 'Next action: wait for normal processing; inspect the file record if delayed.',
    'processing' => 'Processing', 'processing_action' => 'Next action: check processing completion in the file record.',
    'ready' => 'Text prepared', 'ready_action' => 'Next action: search prepared text and verify excerpts against the source file.',
    'ocr_quote_required' => 'OCR quote required', 'ocr_quote_required_action' => 'Next action: open the OCR quote and check its cost before approval.',
    'ocr_approved' => 'OCR approved', 'ocr_approved_action' => 'Next action: wait for recognition and verify the resulting text.',
    'unsupported' => 'Unsupported for text search', 'unsupported_action' => 'Next action: provide a supported text format. Use BIM model search for IFC objects.',
    'damaged' => 'Damaged files', 'damaged_action' => 'Next action: check the source and upload a corrected copy.',
    'failed' => 'Processing failed', 'failed_action' => 'Next action: inspect the file error, resolve its cause and retry through the normal workflow.',
    'unknown' => 'Other status', 'unknown_action' => 'Next action: check the file record.',
    'basis' => 'Registry status inventory, not a full-content verification. Counts are complete; filenames are examples. These statuses concern text search; BIM object indexing is checked separately.',
];
