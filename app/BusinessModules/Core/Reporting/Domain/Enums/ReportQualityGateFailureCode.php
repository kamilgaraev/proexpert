<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportQualityGateFailureCode: string
{
    case MISSING = 'missing';
    case INVALID = 'invalid';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case STALE = 'stale';
    case RELEASE_SHA_MISMATCH = 'release_sha_mismatch';
    case SCHEMA_HASH_MISMATCH = 'schema_hash_mismatch';
    case COMMAND_COUNT_MISMATCH = 'command_count_mismatch';
    case CATALOG_COUNT_MISMATCH = 'catalog_count_mismatch';
    case BINDING_SET_MISMATCH = 'binding_set_mismatch';
    case GROUP_COVERAGE_MISMATCH = 'group_coverage_mismatch';
    case PHASE_INCOMPLETE = 'phase_incomplete';
}
