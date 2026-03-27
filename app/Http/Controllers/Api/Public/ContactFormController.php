<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\StoreContactFormRequest;
use App\Http\Resources\Api\Public\ContactFormResource;
use App\Http\Responses\LandingResponse;
use App\Services\Public\ContactFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

use function trans_message;

class ContactFormController extends Controller
{
    public function __construct(
        protected ContactFormService $contactFormService,
    ) {}

    public function store(StoreContactFormRequest $request): JsonResponse
    {
        try {
            $contactForm = $this->contactFormService->submit($request->validated());

            return LandingResponse::success(
                new ContactFormResource($contactForm),
                trans_message('public_contact.submitted', [], $this->fallbackLocale()),
                201
            );
        } catch (\Throwable $exception) {
            Log::error('Public contact form submission error', [
                'error' => $exception->getMessage(),
                'email' => $request->input('email'),
                'page_source' => $request->input('page_source'),
                'company_role' => $request->input('company_role'),
                'company_size' => $request->input('company_size'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return LandingResponse::error(
                trans_message('public_contact.submit_error', [], $this->fallbackLocale()),
                500
            );
        }
    }

    protected function fallbackLocale(): string
    {
        $locale = config('app.fallback_locale', 'ru');

        return is_string($locale) && $locale !== '' ? $locale : 'ru';
    }
}
