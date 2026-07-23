<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\SignatureCallback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegalDocumentSignatureCallbackController extends Controller
{
    public function __construct(private readonly LegalDocumentSignatureService $signatures) {}

    public function complete(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'provider' => ['required', 'string', 'max:191'],
                'provider_request_id' => ['required', 'string', 'max:191'],
                'correlation_id' => ['required', 'string', 'size:64'],
                'replay_token' => ['required', 'string', 'max:4096'],
                'payload' => ['required', 'array'],
            ]);
            $signature = $this->signatures->completeElectronic(new SignatureCallback(
                (string) $data['provider'],
                (string) $data['provider_request_id'],
                (string) $data['correlation_id'],
                (string) $data['replay_token'],
                (array) $data['payload'],
            ));

            return new JsonResponse(['accepted' => true, 'signature_id' => (int) $signature->id]);
        } catch (Throwable $error) {
            Log::warning('legal_archive.signature.callback_rejected', ['error_class' => $error::class]);

            return new JsonResponse(['accepted' => false], 422);
        }
    }
}
