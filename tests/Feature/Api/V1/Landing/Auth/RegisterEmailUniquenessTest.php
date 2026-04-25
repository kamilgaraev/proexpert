<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Http\Requests\Api\V1\Landing\Auth\RegisterRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        });
    }

    public function test_registration_rejects_existing_email_case_insensitively(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing Owner',
            'email' => 'Owner@Example.test',
            'password' => 'password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = new RegisterRequest();
        $validator = Validator::make([
            'name' => 'New Owner',
            'email' => 'owner@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Test Organization',
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors()->messages());
    }
}
