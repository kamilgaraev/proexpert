<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Security');

pest()->extend(TestCase::class)
    ->in('Unit');

expect()->extend('toBeValidApiResponse', function () {
    return $this->toHaveKeys(['success', 'data']);
});

expect()->extend('toBeSuccessApiResponse', function () {
    return $this
        ->toHaveKey('success')
        ->and($this->value['success'])->toBeTrue();
});

expect()->extend('toBeErrorApiResponse', function (int $statusCode = 0) {
    $assertion = $this
        ->toHaveKey('success')
        ->and($this->value['success'])->toBeFalse();

    if ($statusCode > 0) {
        $assertion->and($this->value)->toHaveKey('message');
    }

    return $assertion;
});

function actingAsUser(\App\Models\User $user): \Illuminate\Testing\TestResponse|Tests\TestCase
{
    return test()->actingAs($user);
}

function makeUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create($attributes);
}
