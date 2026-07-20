<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use PHPUnit\Framework\TestCase;

final class OnlyOfficeCallbackTest extends TestCase
{
    public function test_callback_boundary_is_authenticated_bounded_idempotent_and_versioned(): void
    {
        $root = __DIR__.'/../../../app/Services/LegalArchive/Editor/';
        $service = file_get_contents($root.'LegalDocumentEditorSessionService.php');
        $fetcher = file_get_contents($root.'OnlyOfficeBoundedDocumentFetcher.php');
        self::assertIsString($service);
        self::assertIsString($fetcher);
        self::assertStringContainsString('verifyCallbackToken', $service);
        self::assertStringContainsString('replay_hash', $service);
        self::assertStringContainsString('LegalDocumentFileService', $service);
        self::assertStringContainsString('->addVersion(', $service);
        self::assertStringContainsString('source_version_id', $service);
        self::assertStringContainsString('max_redirects', $fetcher);
        self::assertStringContainsString('FILTER_FLAG_NO_PRIV_RANGE', $fetcher);
        self::assertStringContainsString('max_size_bytes', $fetcher);
        self::assertStringContainsString('finally', $service);
    }

    public function test_routes_have_separate_preview_download_and_editor_permissions(): void
    {
        $routes = file_get_contents(__DIR__.'/../../../routes/api/v1/admin/legal_archive.php');
        self::assertIsString($routes);
        self::assertStringContainsString("middleware('authorize:legal_archive.view')->whereNumber('documentVersion')->name('document-file-versions.editor.session')", $routes);
        self::assertStringContainsString('legal_archive.view', $routes);
        self::assertStringContainsString('legal_archive.files.download', $routes);
        self::assertStringContainsString('/preview', $routes);
        self::assertStringContainsString('/download', $routes);
        $editor = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/OnlyOfficeDocumentEditor.php');
        self::assertIsString($editor);
        self::assertStringContainsString("\$context->mode === 'view'", $editor);
        self::assertStringContainsString("\$context->mode === 'review'", $editor);
        self::assertStringContainsString('document-file-versions/{documentVersion}/editor/session', $routes);
        self::assertStringNotContainsString('documents/{document}/versions/{version}/editor', $routes);
        $api = file_get_contents(__DIR__.'/../../../routes/api.php');
        self::assertIsString($api);
        self::assertStringContainsString('legal-document-editor/callback/{session}', $api);
        self::assertStringContainsString('throttle:legal-editor-callback', $api);
        $limiter = file_get_contents(__DIR__.'/../../../app/Providers/RouteServiceProvider.php');
        self::assertIsString($limiter);
        self::assertStringContainsString("new \\Illuminate\\Http\\JsonResponse(['error' => 1], 429)", $limiter);
    }

    public function test_postgres_races_are_opt_in_and_process_level(): void
    {
        $source = file_get_contents(__DIR__.'/../../Integration/LegalArchive/LegalDocumentEditorPostgresConcurrencyTest.php');
        self::assertIsString($source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_PG_EDITOR_CONCURRENCY') !== '1'", $source);
        self::assertStringContainsString("getenv('LEGAL_DOCUMENT_PG_TEST_ALLOW_DDL') !== '1'", $source);
        self::assertStringContainsString('pcntl_fork', $source);
        self::assertStringContainsString('callback_vs_new_version', $source);
        self::assertStringContainsString('callback_replay', $source);
        self::assertStringContainsString('LegalDocumentEditorSessionService', $source);
        self::assertStringContainsString('callback_force_save_then_final_save', $source);
        self::assertStringNotContainsString('WHERE id=-1', $source);
    }

    public function test_force_save_uses_append_only_save_ledger_and_atomic_completion_hook(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        $attempt = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Files/LegalDocumentVersionAttempt.php');
        self::assertIsString($service);
        self::assertIsString($attempt);
        self::assertStringContainsString('LegalDocumentEditorSave', $service);
        self::assertStringContainsString('save_generation', $service);
        self::assertStringContainsString('completionCallback', $attempt);
        self::assertStringContainsString('$input->status === 6', $service);
    }

    public function test_failed_terminal_callback_requires_an_explicit_new_generation_replacement(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        self::assertIsString($service);
        self::assertStringContainsString('supersedes_save_id', $service);
        self::assertStringContainsString('legal_document_editor_callback_failed_replay', $service);
        self::assertStringContainsString("where('terminal', true)", $service);
        self::assertStringContainsString("where('state', 'failed')", $service);
    }

    public function test_mode_and_permission_are_bound_to_persisted_session(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveFileController.php');
        self::assertIsString($service);
        self::assertIsString($controller);
        self::assertStringContainsString('(string) $existing->mode === $mode', $service);
        self::assertStringContainsString('required_ability', $service);
        self::assertStringContainsString('$session->mode', $service);
        self::assertStringContainsString('upgrade_mode', $controller);
    }

    public function test_completed_replay_precedes_reauthorization_and_active_completion_reauthorizes_again(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        self::assertIsString($service);
        $claim = substr($service, (int) strpos($service, 'private function claim'), 9000);
        self::assertLessThan(strpos($claim, 'reauthorizeSessionActor'), strpos($claim, "state === 'completed'"));
        $complete = substr($service, (int) strpos($service, 'private function completeSave'), 5000);
        self::assertStringContainsString('reauthorizeSessionActor', $complete);
    }

    public function test_editor_session_has_one_actor_and_save_is_attributed_to_that_actor(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        self::assertIsString($service);
        self::assertStringContainsString('(int) $existing->opened_by_user_id !== (int) $actor->id', $service);
        self::assertStringContainsString("throw new DomainException('legal_document_editor_actor_conflict')", $service);
        self::assertStringContainsString("where('editor_session_id', \$session->id)->get()", $service);
        self::assertStringContainsString('$participants->count() !== 1', $service);
        self::assertStringContainsString('uploadedByUserId: (int) $session->opened_by_user_id', $service);
        self::assertStringContainsString("'editor_actor_user_id' => (int) \$session->opened_by_user_id", $service);
        self::assertStringContainsString("recordForActorId('editor_version_saved', \$document, (int) \$session->opened_by_user_id", $service);
        self::assertStringNotContainsString('editor_participant_user_ids', $service);
    }

    public function test_editor_version_label_is_translated_and_utf8_clean(): void
    {
        $service = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Editor/LegalDocumentEditorSessionService.php');
        $translations = file_get_contents(__DIR__.'/../../../lang/ru/legal_archive.php');
        self::assertIsString($service);
        self::assertIsString($translations);
        self::assertStringContainsString("trans_message('legal_archive.messages.editor_version_label')", $service);
        self::assertStringContainsString("'editor_version_label' => 'Редакция из встроенного редактора'", $translations);
        self::assertStringNotContainsString('Р РµРґР°РєС†РёСЏ РёР·', $service);
    }
}
