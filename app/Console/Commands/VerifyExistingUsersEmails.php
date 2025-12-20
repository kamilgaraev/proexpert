<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyExistingUsersEmails extends Command
{
    protected $signature = 'users:verify-existing-emails';
    protected $description = 'Проставляет email_verified_at для всех существующих пользователей без подтвержденного email';

    public function handle()
    {
        $this->info('Начинаю проверку пользователей...');
        
        $unverifiedCount = User::whereNull('email_verified_at')->count();
        $this->info("Найдено неподтвержденных пользователей: {$unverifiedCount}");
        
        if ($unverifiedCount === 0) {
            $this->info('Все пользователи уже имеют подтвержденный email.');
            return 0;
        }
        
        if (!$this->confirm('Продолжить и проставить email_verified_at для всех существующих пользователей?')) {
            $this->info('Операция отменена.');
            return 0;
        }
        
        $updated = DB::table('users')
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => now(),
                'updated_at' => now()
            ]);
        
        $this->info("Успешно обновлено пользователей: {$updated}");
        
        return 0;
    }
}

