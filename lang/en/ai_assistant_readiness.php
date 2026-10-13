<?php

declare(strict_types=1);

return [
    'description' => 'Current authorized document text-search readiness: complete status counts, up to three examples per status and next actions. Null project means all authorized organization documents. Metadata only; no content summary or content completeness claim.',
    'scope_all' => 'Authorized organization file and document text-search readiness.',
    'scope_project' => 'Authorized project #:project file and document text-search readiness.',
    'file_counts' => 'Accessible files and attachments: :total. Text ready: :ready; awaiting processing: :pending; OCR required: :ocr; OCR processing: :ocr_processing; processing failed: :failed; text search unsupported: :unsupported; empty text: :empty; source file access review required: :review; attachment content and storage unverified: :unverified.',
    'registered_counts' => 'Registered assistant documents: :total. Their statuses and examples follow. These documents and accessible files are counted separately; do not add the counts.',
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
    'basis' => 'File coverage includes accessible attachments not yet registered with the assistant. Statuses and filenames below refer to registered documents; filenames are examples. Full file contents were not verified. Text search and BIM object indexing are checked separately.',
];
