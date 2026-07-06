<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Http\Requests\Api\V1\Brigades\Auth\RegisterBrigadeRequest;
use App\Http\Requests\Api\V1\Customer\Auth\RegisterRequest as CustomerRegisterRequest;
use App\Http\Requests\Api\V1\Customer\Auth\ResetPasswordRequest as CustomerResetPasswordRequest;
use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest as LandingRegisterRequest;
use App\Http\Requests\Api\V1\Landing\Auth\ResetPasswordRequest as LandingResetPasswordRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RegisterEmailUniquenessTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_landing_registration_rejects_existing_active_email_case_insensitively(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Owner',
            'email' => 'Owner@Example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $validator = $this->makeValidator(new LandingRegisterRequest(), 'owner@example.test');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->messages());
    }

    public function test_landing_registration_allows_deleted_email(): void
    {
        DB::table('users')->insert([
            'name' => 'Deleted Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $validator = $this->makeValidator(new LandingRegisterRequest(), 'owner@example.test');

        $this->assertFalse($validator->fails());
    }

    public function test_customer_registration_rejects_existing_active_email_case_insensitively(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Owner',
            'email' => 'Owner@Example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $validator = $this->makeValidator(new CustomerRegisterRequest(), 'owner@example.test');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->messages());
    }

    public function test_customer_registration_allows_deleted_email(): void
    {
        DB::table('users')->insert([
            'name' => 'Deleted Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $validator = $this->makeValidator(new CustomerRegisterRequest(), 'owner@example.test');

        $this->assertFalse($validator->fails());
    }

    public function test_landing_reset_password_requires_mixed_case_and_digit(): void
    {
        $validator = Validator::make([
            'token' => 'token',
            'email' => 'owner@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], (new LandingResetPasswordRequest())->rules(), (new LandingResetPasswordRequest())->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->messages());
        $this->assertContains(
            trans_message('auth.validation.password_complexity'),
            $validator->errors()->messages()['password']
        );
    }

    public function test_customer_reset_password_requires_mixed_case_and_digit(): void
    {
        $validator = Validator::make([
            'token' => 'token',
            'email' => 'owner@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], (new CustomerResetPasswordRequest())->rules(), (new CustomerResetPasswordRequest())->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->messages());
        $this->assertContains(
            trans_message('customer.auth.validation.password_complexity'),
            $validator->errors()->messages()['password']
        );
    }

    public function test_brigade_registration_requires_mixed_case_and_digit(): void
    {
        $request = new RegisterBrigadeRequest();
        $validator = Validator::make([
            'name' => 'Brigade',
            'contact_person' => 'Owner',
            'contact_phone' => '+79990000000',
            'contact_email' => 'brigade@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->messages());
        $this->assertContains(
            trans_message('auth.validation.password_complexity'),
            $validator->errors()->messages()['password']
        );
    }

    private function makeValidator(FormRequest $request, string $email): \Illuminate\Validation\Validator
    {
        return Validator::make([
            'name' => 'New Owner',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Test Organization',
        ], $request->rules(), $request->messages());
    }
}
