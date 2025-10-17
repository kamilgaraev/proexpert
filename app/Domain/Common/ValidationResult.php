<?php

namespace App\Domain\Common;

class ValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors = [],
        public readonly ?string $message = null,
        public readonly ?string $code = null,
        public readonly string $level = 'success',
    ) {}
    
    public static function success(): self
    {
        return new self(
            isValid: true,
            message: null,
            code: null,
            level: 'success',
        );
    }
    
    public static function warning(string $message, ?string $code = null): self
    {
        return new self(
            isValid: true, // Warning не блокирует
            message: $message,
            code: $code,
            level: 'warning',
        );
    }
    
    public static function error(string $message, ?string $code = null): self
    {
        return new self(
            isValid: false,
            message: $message,
            code: $code,
            level: 'error',
        );
    }
    
    public function isSuccess(): bool
    {
        return $this->isValid && $this->level === 'success';
    }
    
    public function isWarning(): bool
    {
        return $this->level === 'warning';
    }
    
    public function isError(): bool
    {
        return $this->level === 'error';
    }
    
    public function toArray(): array
    {
        return [
            'is_valid' => $this->isValid,
            'errors' => $this->errors,
            'message' => $this->message,
            'code' => $this->code,
            'level' => $this->level,
        ];
    }
}

