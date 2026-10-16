<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Exceptions\Billing\PaymentGatewayConfigurationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TochkaClient
{
    public const BASE_URL = 'https://enter.tochka.com/uapi';

    public function ready(): bool
    {
        $path = (string) config('bank_billing.jwt_path');
        $resolved = $path === '' ? false : realpath($path);
        $checkout = str_replace('\\', '/', base_path()).'/';

        return config('bank_billing.enabled') === true
            && is_string($resolved) && ! str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower($checkout))
            && is_file($resolved) && is_readable($resolved)
            && (PHP_OS_FAMILY === 'Windows' || (fileperms($resolved) & 0137) === 0)
            && preg_match('/\A\d{20}\/\d{9}\z/', (string) config('bank_billing.account_id')) === 1
            && trim((string) config('bank_billing.customer_code')) !== '';
    }

    public function initializeStatement(string $date): string
    {
        $response = $this->request('POST', '/open-banking/v1.0/statements', [
            'Data' => ['Statement' => [
                'accountId' => (string) config('bank_billing.account_id'),
                'startDateTime' => $date,
                'endDateTime' => $date,
            ]],
        ]);
        $statement = $response['Data']['Statement'] ?? null;
        if (! is_array($statement) || ! is_string($statement['statementId'] ?? null)
            || ($statement['accountId'] ?? null) !== config('bank_billing.account_id')) {
            throw new RuntimeException('tochka_statement_response_invalid');
        }

        return $statement['statementId'];
    }

    public function statement(string $statementId): array
    {
        $account = implode('/', array_map('rawurlencode', explode('/', (string) config('bank_billing.account_id'))));

        return $this->request('GET', '/open-banking/v1.0/accounts/'.$account.'/statements/'.rawurlencode($statementId));
    }

    public function createDocument(string $kind, array $payload): string
    {
        $response = $this->request('POST', $this->documentPath($kind), $payload);
        $id = $response['Data']['documentId'] ?? null;
        if (! is_string($id) || ! \Illuminate\Support\Str::isUuid($id)) {
            throw new RuntimeException('tochka_document_response_invalid');
        }

        return $id;
    }

    public function documentPdf(string $kind, string $customerCode, string $documentId): string
    {
        if (! \Illuminate\Support\Str::isUuid($documentId) || $customerCode === '') {
            throw new RuntimeException('tochka_document_identity_invalid');
        }
        $response = $this->send('GET', $this->documentPath($kind).'/'.rawurlencode($customerCode).'/'.rawurlencode($documentId).'/file', [],
            (int) config('bank_billing.maximum_document_bytes'), 'application/pdf');
        if (! str_starts_with($response->body(), '%PDF-')) {
            throw new RuntimeException('tochka_document_invalid');
        }

        return $response->body();
    }

    public function createB2bQr(string $merchantId, string $accountId, array $payload): array
    {
        $account = implode('/', array_map('rawurlencode', explode('/', $accountId)));

        return $this->request('POST', '/sbp/v1.0/b2b-qr-code/merchant/'.rawurlencode($merchantId).'/'.$account, ['Data' => $payload]);
    }

    public function b2bQr(string $qrcId): array
    {
        return $this->request('GET', '/sbp/v1.0/b2b-qr-code/'.rawurlencode($qrcId));
    }

    private function documentPath(string $kind): string
    {
        return match ($kind) {
            'invoice' => '/invoice/v1.0/bills',
            'closing' => '/invoice/v1.0/closing-documents',
            default => throw new RuntimeException('tochka_document_kind_invalid'),
        };
    }

    protected function request(string $method, string $path, array $payload = []): array
    {
        $response = $this->send($method, $path, $payload, (int) config('bank_billing.maximum_statement_bytes', 10_000_000));
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('tochka_response_invalid');
        }

        return $data;
    }

    private function send(string $method, string $path, array $payload, int $maximum, string $accept = 'application/json'): Response
    {
        if (! $this->ready()) {
            throw new PaymentGatewayConfigurationException(trans_message('bank_billing.unavailable'));
        }
        $key = file_get_contents((string) config('bank_billing.jwt_path'), false, null, 0, 8193);
        if (! is_string($key) || strlen($key) > 8192 || trim($key) === '') {
            throw new PaymentGatewayConfigurationException(trans_message('bank_billing.unavailable'));
        }
        try {
            $response = Http::withToken(trim($key))->accept($accept)->connectTimeout(5)
                ->timeout((int) config('bank_billing.timeout_seconds', 15))
                ->withOptions(['allow_redirects' => false, 'progress' => static function ($total, $received) use ($maximum): void {
                    if ($total > $maximum || $received > $maximum) {
                        throw new RuntimeException('tochka_response_too_large');
                    }
                }])
                ->send($method, self::BASE_URL.$path, $method === 'GET' ? [] : ['json' => $payload]);
        } catch (Throwable) {
            throw new RuntimeException('tochka_connection_failed');
        }
        if (! $response->successful()) {
            throw new RuntimeException('tochka_http_'.$response->status());
        }
        if (strlen($response->body()) > $maximum) {
            throw new RuntimeException('tochka_response_too_large');
        }

        return $response;
    }
}
