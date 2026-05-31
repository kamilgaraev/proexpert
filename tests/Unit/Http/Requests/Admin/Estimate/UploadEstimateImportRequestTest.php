<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin\Estimate;

use App\Http\Requests\Admin\Estimate\UploadEstimateImportRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UploadEstimateImportRequestTest extends TestCase
{
    public function test_it_accepts_pdf_by_extension_and_pdf_signature(): void
    {
        $validator = $this->validatorForFile(
            UploadedFile::fake()->createWithContent('estimate.PDF', "%PDF-1.7\n")
        );

        self::assertTrue($validator->passes(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_it_rejects_pdf_extension_without_pdf_signature(): void
    {
        $validator = $this->validatorForFile(
            UploadedFile::fake()->createWithContent('estimate.pdf', 'not a pdf')
        );

        self::assertFalse($validator->passes());
    }

    private function validatorForFile(UploadedFile $file): \Illuminate\Validation\Validator
    {
        $request = new UploadEstimateImportRequest();

        return Validator::make(
            ['file' => $file],
            $request->rules(),
            $request->messages()
        );
    }
}
