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
        // Создаем функцию для генерации следующего номера документа с учетом организации, типа и периода
        DB::statement("
            CREATE OR REPLACE FUNCTION get_next_payment_document_number(
                org_id INTEGER, 
                doc_type TEXT, 
                year_val INTEGER,
                month_val INTEGER
            )
            RETURNS TEXT AS $$
            DECLARE
                sequence_name TEXT;
                next_num INTEGER;
                max_existing_num INTEGER;
                prefix TEXT;
                result TEXT;
            BEGIN
                -- Определяем префикс на основе типа документа
                prefix := CASE doc_type
                    WHEN 'payment_request' THEN 'ПТ'
                    WHEN 'invoice' THEN 'СЧ'
                    WHEN 'payment_order' THEN 'ПП'
                    WHEN 'incoming_payment' THEN 'ВП'
                    WHEN 'expense' THEN 'РО'
                    WHEN 'offset_act' THEN 'АВЗ'
                    ELSE 'DOC'
                END;
                
                -- Имя последовательности для организации, типа документа, года и месяца
                sequence_name := 'payment_doc_seq_' || org_id || '_' || doc_type || '_' || year_val || '_' || month_val;
                
                -- Проверяем существует ли последовательность
                IF NOT EXISTS (
                    SELECT 1 FROM pg_class 
                    WHERE relname = sequence_name 
                    AND relkind = 'S'
                ) THEN
                    -- Находим максимальный существующий номер для этой организации, типа и периода
                    SELECT COALESCE(
                        MAX(CAST(SUBSTRING(document_number FROM '[0-9]+$') AS INTEGER)),
                        0
                    )
                    INTO max_existing_num
                    FROM payment_documents
                    WHERE organization_id = org_id
                    AND document_type = doc_type
                    AND document_number LIKE prefix || '-' || year_val || LPAD(month_val::TEXT, 2, '0') || '-%';
                    
                    -- Создаем новую последовательность начиная со следующего номера после максимального
                    EXECUTE 'CREATE SEQUENCE ' || quote_ident(sequence_name) || ' START ' || (max_existing_num + 1);
                END IF;
                
                -- Получаем следующее значение из последовательности (thread-safe)
                EXECUTE 'SELECT nextval(' || quote_literal(sequence_name) || ')' INTO next_num;
                
                -- Формируем номер документа в формате PREFIX-YYYYMM-NNNN
                result := prefix || '-' || year_val || LPAD(month_val::TEXT, 2, '0') || '-' || LPAD(next_num::TEXT, 4, '0');
                
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
        DB::statement("DROP FUNCTION IF EXISTS get_next_payment_document_number(INTEGER, TEXT, INTEGER, INTEGER)");
        
        // Удаляем все sequences для платежных документов
        DB::statement("
            DO $$ 
            DECLARE 
                seq_name TEXT;
            BEGIN
                FOR seq_name IN 
                    SELECT relname 
                    FROM pg_class 
                    WHERE relname LIKE 'payment_doc_seq_%' 
                    AND relkind = 'S'
                LOOP
                    EXECUTE 'DROP SEQUENCE IF EXISTS ' || quote_ident(seq_name);
                END LOOP;
            END $$;
        ");
    }
};

