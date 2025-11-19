<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Создаем функцию для генерации следующего номера счета
        DB::statement("
            CREATE OR REPLACE FUNCTION get_next_invoice_number(org_id INTEGER, year_val INTEGER)
            RETURNS TEXT AS $$
            DECLARE
                sequence_name TEXT;
                next_num INTEGER;
                result TEXT;
            BEGIN
                -- Имя последовательности для организации и года
                sequence_name := 'invoice_seq_' || org_id || '_' || year_val;
                
                -- Проверяем существует ли последовательность
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class 
                    WHERE relname = sequence_name 
                    AND relkind = 'S'
                ) THEN
                    -- Создаем новую последовательность
                    EXECUTE 'CREATE SEQUENCE ' || quote_ident(sequence_name) || ' START 1';
                END IF;
                
                -- Получаем следующее значение
                EXECUTE 'SELECT nextval(' || quote_literal(sequence_name) || ')' INTO next_num;
                
                -- Формируем номер счета
                result := 'INV-' || year_val || '-' || LPAD(next_num::TEXT, 6, '0');
                
                RETURN result;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем функцию
        DB::statement("DROP FUNCTION IF EXISTS get_next_invoice_number(INTEGER, INTEGER)");
        
        // Удаляем все sequences для счетов
        DB::statement("
            DO $$ 
            DECLARE 
                seq_name TEXT;
            BEGIN
                FOR seq_name IN 
                    SELECT relname 
                    FROM pg_class 
                    WHERE relname LIKE 'invoice_seq_%' 
                    AND relkind = 'S'
                LOOP
                    EXECUTE 'DROP SEQUENCE IF EXISTS ' || quote_ident(seq_name);
                END LOOP;
            END $$;
        ");
    }
};

