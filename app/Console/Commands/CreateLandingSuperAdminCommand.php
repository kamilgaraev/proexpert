<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LandingAdmin;
use Illuminate\Support\Facades\Hash;

class CreateLandingSuperAdminCommand extends Command
{
    protected $signature = 'landing-admin:create-super
                            {--email= : Email for the super admin}
                            {--password= : Password for the super admin}
                            {--name=Super\ Admin : Name for the super admin}';

    protected $description = 'Create the first (or additional) Landing Super Admin account';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');
        $name = $this->option('name');

        if (LandingAdmin::where('email', $email)->exists()) {
            $this->error('User with this email already exists.');
            return Command::FAILURE;
        }

        $admin = LandingAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'super_admin',
        ]);

        $admin->is_super = true;
        $admin->save();

        // attach role relation if super_admin role exists
        $roleModel = \App\Models\Role::where('slug', 'super_admin')->first();
        if ($roleModel) {
            $admin->roles()->attach($roleModel->id);
        }

        $this->info("Super admin created with ID {$admin->id}");
        return Command::SUCCESS;
    }
} 