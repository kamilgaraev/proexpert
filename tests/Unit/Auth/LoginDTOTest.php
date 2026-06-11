<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\DTOs\Auth\LoginDTO;
use PHPUnit\Framework\TestCase;

class LoginDTOTest extends TestCase
{
    public function testEmailIsNormalized(): void
    {
        $dto = new LoginDTO('  USER@Example.COM  ', 'password');

        self::assertSame('user@example.com', $dto->getEmail());
        self::assertSame('user@example.com', $dto->toArray()['email']);
    }
}
