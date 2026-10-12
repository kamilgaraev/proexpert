<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_parties', function (Blueprint $table): void {
            $table->string('legal_name', 512)->nullable()->change();
            $table->string('inn', 32)->nullable()->change();
            $table->string('ogrn', 64)->nullable()->change();
        });
        Schema::table('legal_document_signatures', function (Blueprint $table): void { $table->string('signer_name', 512)->nullable()->change(); });
        DB::table('contract_revision_documents as generation')->join('contract_builder_revisions as revision', 'revision.id', '=', 'generation.revision_id')
            ->join('contract_builder_instances as instance', 'instance.id', '=', 'revision.instance_id')
            ->join('contracts as contract', 'contract.id', '=', 'instance.contract_id')
            ->join('legal_archive_documents as document', 'document.id', '=', 'generation.document_id')
            ->join('legal_archive_document_versions as version', 'version.id', '=', 'generation.document_version_id')
            ->where('generation.status', 'ready')->where('version.processing_status', 'ready')
            ->whereColumn('generation.organization_id', 'contract.organization_id')->whereColumn('generation.organization_id', 'document.organization_id')
            ->whereColumn('generation.organization_id', 'version.organization_id')->whereColumn('generation.document_id', 'version.document_id')
            ->whereColumn('generation.document_id', 'contract.legal_archive_document_id')->whereColumn('generation.content_hash', 'revision.content_hash')
            ->whereColumn('generation.file_hash', 'version.content_hash')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('legal_document_party_snapshot_sets as snapshot')->whereColumn('snapshot.document_version_id', 'version.id')
                    ->whereColumn('snapshot.document_id', 'document.id')->whereColumn('snapshot.organization_id', 'document.organization_id');
            })
            ->select(['generation.id', 'generation.organization_id', 'generation.document_id', 'generation.document_version_id', 'generation.created_by', 'revision.parties'])
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $parties = json_decode($row->parties, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($parties) || count($parties) < 2) throw new RuntimeException('contract_revision_party_snapshot_invalid');
                    $snapshotId = DB::table('legal_document_party_snapshot_sets')->insertGetId([
                        'organization_id' => $row->organization_id, 'document_id' => $row->document_id, 'document_version_id' => $row->document_version_id,
                        'captured_at' => now(), 'captured_by_user_id' => $row->created_by, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    foreach ($parties as $party) {
                        DB::table('legal_document_parties')->insert([
                            'organization_id' => $row->organization_id, 'document_id' => $row->document_id,
                            'document_version_id' => $row->document_version_id, 'snapshot_set_id' => $snapshotId,
                            'party_organization_id' => $party['linked_organization_id'], 'party_role' => $party['side'] === 'first' ? 'customer' : ($party['side'] === 'second' ? 'contractor' : 'other'),
                            'legal_name' => $party['legal_name'] ?: $party['name'], 'tax_number' => $party['inn'], 'registration_number' => $party['ogrn'],
                            'legal_address' => $party['legal_address'],
                            'bank_details' => json_encode(array_intersect_key($party, array_flip(['bank_name', 'bank_bic', 'settlement_account', 'correspondent_account'])), JSON_THROW_ON_ERROR),
                            'representative_name' => $party['representative_name'] ?? null, 'representative_position' => $party['representative_position'] ?? null,
                            'authority_basis' => $party['authority_basis'] ?? null, 'data_source' => $party['linked_organization_id'] === null ? 'manual' : 'organization',
                            'snapshot' => json_encode($party, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }, 'generation.id', 'id');
    }

    public function down(): void
    {
        if (DB::table('contract_parties')->whereRaw('char_length(legal_name) > 255 OR char_length(inn) > 12 OR char_length(ogrn) > 15')->exists()
            || DB::table('legal_document_signatures')->whereRaw('char_length(signer_name) > 255')->exists()) throw new RuntimeException('contract_party_identity_requires_preservation');
        Schema::table('contract_parties', function (Blueprint $table): void {
            $table->string('legal_name', 255)->nullable()->change();
            $table->string('inn', 12)->nullable()->change();
            $table->string('ogrn', 15)->nullable()->change();
        });
        Schema::table('legal_document_signatures', function (Blueprint $table): void { $table->string('signer_name', 255)->nullable()->change(); });
    }
};
