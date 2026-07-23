<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fromUnit = '10 шт';
        $toUnit = 'шт';
        $factor = '10.000000000000';
        $version = 1;
        $fingerprint = hash('sha256', implode('|', [$fromUnit, $toUnit, $factor, (string) $version]));

        DB::table('estimate_generation_unit_conversions')->insertOrIgnore([
            'from_unit' => '10 шт',
            'to_unit' => 'шт',
            'factor' => '10.000000000000',
            'version' => 1,
            'fingerprint' => $fingerprint,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registered = DB::table('estimate_generation_unit_conversions')
            ->where('from_unit', $fromUnit)
            ->where('to_unit', $toUnit)
            ->where('version', $version)
            ->first(['factor', 'fingerprint', 'is_active']);
        if ($registered === null
            || (string) $registered->factor !== $factor
            || ! hash_equals($fingerprint, (string) $registered->fingerprint)
            || (bool) $registered->is_active !== true) {
            throw new RuntimeException('estimate_generation.scaled_piece_conversion_conflict');
        }
    }

    public function down(): void {}
};
