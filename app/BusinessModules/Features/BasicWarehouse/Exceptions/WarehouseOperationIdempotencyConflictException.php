<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Exceptions;

use DomainException;

final class WarehouseOperationIdempotencyConflictException extends DomainException {}
