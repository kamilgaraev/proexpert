<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Exceptions;

use DomainException;

final class PaymentBudgetLimitException extends DomainException {}
