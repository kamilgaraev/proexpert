<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\Http\Requests\ConstructionJournal\MobileStoreConstructionJournalRequest;
use App\Http\Requests\ConstructionJournal\StoreConstructionJournalRequest;
use App\Http\Requests\ConstructionJournal\UpdateConstructionJournalRequest;
use PHPUnit\Framework\TestCase;

class JournalHttpBoundaryContractTest extends TestCase
{
    public function test_validation_errors_are_returned_as_422_before_the_generic_handler(): void
    {
        foreach ([
            'app/Http/Controllers/Api/ConstructionJournalController.php',
            'app/Http/Controllers/Api/V1/Mobile/ConstructionJournalController.php',
        ] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.$path);

            self::assertStringContainsString('use Illuminate\Validation\ValidationException;', $source);
            self::assertMatchesRegularExpression(
                '/catch \(ValidationException .*?422.*?catch \(DomainException/s',
                $source
            );
        }
    }

    public function test_admin_and_mobile_page_sizes_are_bounded(): void
    {
        $admin = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Http/Controllers/Api/ConstructionJournalController.php'
        );
        $mobileController = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Http/Controllers/Api/V1/Mobile/ConstructionJournalController.php'
        );
        $mobileService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Services/Mobile/MobileConstructionJournalService.php'
        );

        self::assertGreaterThanOrEqual(2, substr_count($admin, 'min(100, max(1,'));
        self::assertStringContainsString('min(100, max(1, $request->integer(\'per_page\'', $mobileController);
        self::assertStringContainsString("min(100, max(1, (int) (\$filters['per_page']", $mobileService);
    }

    public function test_journal_http_validation_is_owned_by_form_requests_and_requires_a_contract(): void
    {
        $storeRules = (new StoreConstructionJournalRequest)->rules();
        $mobileStoreRules = (new MobileStoreConstructionJournalRequest)->rules();
        $updateRules = (new UpdateConstructionJournalRequest)->rules();

        self::assertContains('required', $storeRules['contract_id']);
        self::assertContains('exists:contracts,id', $storeRules['contract_id']);
        self::assertContains('required', $mobileStoreRules['project_id']);
        self::assertContains('required', $updateRules['contract_id']);

        foreach ([
            'app/Http/Controllers/Api/ConstructionJournalController.php',
            'app/Http/Controllers/Api/V1/Mobile/ConstructionJournalController.php',
        ] as $path) {
            $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.$path);

            self::assertStringContainsString('$request->validated()', $source);
            self::assertStringNotContainsString("'contract_id' => 'nullable|integer'", $source);
        }
    }
}
