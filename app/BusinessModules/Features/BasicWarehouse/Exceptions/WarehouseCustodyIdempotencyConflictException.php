<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Exceptions;

use RuntimeException;

final class WarehouseCustodyIdempotencyConflictException extends RuntimeException {}
