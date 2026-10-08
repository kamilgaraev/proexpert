<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway;

use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use Closure;
use CurlHandle;
use LogicException;
use Throwable;

final class GatewayPublicCoreHttpSender
{
    public const ENDPOINT = 'https://api.timeweb.ai/v1/responses';

    public const MAX_RESPONSE_BYTES = 262144;

    private ?array $lifecycle = null;

    private bool $sending = false;

    private bool $boundConsumed = false;

    public function __construct(
        private readonly ?Closure $credentialReader = null,
        private readonly ?Closure $executor = null,
        private readonly ?Closure $nativeTestSetup = null,
        private readonly ?Closure $nativeTestRemove = null,
    ) {}

    public function sendBound(GatewayModelProfile $profile, GatewayModelRequest $request, string $channelRef, Closure $beforeWrite, Closure $uploaded, ?Closure $cancel = null, ?Closure $stopped = null): array
    {
        if ($this->lifecycle !== null || ! GatewayModelRequest::isReference($channelRef)
            || $request->profileFingerprint !== $profile->fingerprint()) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $this->lifecycle = [
            'schemaVersion' => 'public-core-gateway-native-lifecycle/1',
            'transferRef' => 'transfer:'.bin2hex(random_bytes(16)),
            'channelRef' => $channelRef,
            'binding' => $request->binding(),
            'state' => 'pending',
            'nativeStarted' => false,
            'bodyLength' => strlen($request->bodyBytes),
            'reasonCode' => 'none',
        ];

        return $this->send($profile, $request->bodyBytes, $beforeWrite, $uploaded, $cancel, $stopped);
    }

    public function nativeLifecycle(GatewayModelRequest $request, string $channelRef): array
    {
        if ($this->executor !== null || $this->lifecycle === null || $this->lifecycle['channelRef'] !== $channelRef
            || GatewayModelRequest::canonicalJson($this->lifecycle['binding']) !== GatewayModelRequest::canonicalJson($request->binding())) {
            throw new LogicException('gateway_channel_unavailable');
        }

        return $this->lifecycle;
    }

    public function send(GatewayModelProfile $profile, string $bodyBytes, ?Closure $beforeWrite = null, ?Closure $uploaded = null, ?Closure $cancel = null, ?Closure $stopped = null): array
    {
        if ($this->sending || ($this->lifecycle !== null && $this->boundConsumed)) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $this->sending = true;
        $this->boundConsumed = $this->lifecycle !== null;
        $handle = null;
        try {
            if ($this->lifecycle !== null && $this->lifecycle['state'] !== 'pending') {
                throw new LogicException('gateway_channel_unavailable');
            }
            if (! $profile->isActualProfile() || $profile->values()['endpoint'] !== self::ENDPOINT
                || $profile->values()['apiMethod'] !== 'responses') {
                throw new LogicException('model_profile_unqualified');
            }
            $validator = new GatewayPublicCoreRequestValidator;
            $reason = $validator->validateBody($profile, $bodyBytes);
            if ($reason !== null) {
                throw new LogicException($reason);
            }
            if ($beforeWrite === null || $uploaded === null) {
                throw new LogicException('gateway_channel_unavailable');
            }
            if ($this->credentialReader === null || ! extension_loaded('curl')) {
                throw new LogicException('provider_credentials_unavailable');
            }
            $credential = ($this->credentialReader)();
            if (! is_string($credential) || preg_match('/\A[!-~]{16,4096}\z/D', $credential) !== 1) {
                throw new LogicException('provider_credentials_unavailable');
            }
            $handle = curl_init();
            if (! $handle instanceof CurlHandle) {
                throw new LogicException('gateway_unavailable');
            }
            $response = '';
            $overflow = false;
            $uploadReported = false;
            $uploadDeadline = null;
            $readDeadline = static function () use (&$uploadDeadline): ?int {
                return $uploadDeadline;
            };
            $callbackReason = null;
            $headerBytes = 0;
            $length = strlen($bodyBytes);
            $nativeObservation = $this->executor === null;
            $options = [
                CURLOPT_URL => self::ENDPOINT,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $bodyBytes,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$credential, 'Expect:'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_TIMEOUT_MS => 10000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $curl, string $chunk) use (&$response, &$overflow): int {
                    if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $overflow = true;

                        return 0;
                    }
                    $response .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$headerBytes, &$overflow): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 16384) {
                        $overflow = true;

                        return 0;
                    }

                    return strlen($line);
                },
                CURLOPT_XFERINFOFUNCTION => function (CurlHandle $curl, float $downloadTotal, float $downloadNow, float $uploadTotal, float $uploadNow) use ($length, $uploaded, &$uploadReported, &$callbackReason, $readDeadline, $nativeObservation): int {
                    $deadline = $readDeadline();
                    if (! $uploadReported && (! is_int($deadline) || hrtime(true) >= $deadline)) {
                        $callbackReason = 'expired';

                        return 1;
                    }
                    if (! $uploadReported && $uploadTotal === (float) $length && $uploadNow === (float) $length
                        && (! $nativeObservation || (float) curl_getinfo($curl, CURLINFO_SIZE_UPLOAD) === (float) $length)) {
                        $uploadReported = true;
                        if ($nativeObservation && $this->lifecycle !== null) {
                            $this->lifecycle['state'] = 'uploaded';
                        }
                        try {
                            $uploaded($length);
                        } catch (Throwable $error) {
                            $callbackReason = $error instanceof LogicException && in_array($error->getMessage(), GatewayModelResponse::REASON_CODES, true)
                                ? $error->getMessage() : 'gateway_channel_unavailable';

                            return 1;
                        }
                    }

                    return 0;
                },
            ];
            if (! curl_setopt_array($handle, $options)) {
                throw new LogicException('gateway_unavailable');
            }
            $uploadDeadline = $beforeWrite();
            $now = hrtime(true);
            if (! is_int($uploadDeadline) || $uploadDeadline <= $now || $uploadDeadline - $now > 2000000000) {
                throw new LogicException('expired');
            }
            if ($this->executor !== null) {
                $result = ($this->executor)($handle, $options);
            } else {
                if ($this->nativeTestSetup !== null) {
                    ($this->nativeTestSetup)($handle);
                }
                $isUploaded = static function () use (&$uploadReported): bool {
                    return $uploadReported;
                };
                $failure = static function () use (&$callbackReason): ?string {
                    return $callbackReason;
                };
                $takeHandle = static function () use (&$handle): CurlHandle {
                    if (! $handle instanceof CurlHandle) {
                        throw new LogicException('gateway_unavailable');
                    }
                    $owned = $handle;
                    $handle = null;

                    return $owned;
                };
                $result = $this->performNative($takeHandle, $uploadDeadline, $isUploaded, $failure, $cancel, $stopped);
            }
            if ($callbackReason !== null) {
                throw new LogicException($callbackReason);
            }
            if ($overflow) {
                throw new LogicException('invalid_model_output');
            }
            if (! GatewayModelRequest::hasExactKeys($result, ['status', 'errorCode', 'uploadedBytes'])
                || $result['errorCode'] !== 0 || $result['status'] !== 200
                || ! is_numeric($result['uploadedBytes']) || (float) $result['uploadedBytes'] !== (float) $length
                || ! $uploadReported) {
                throw new LogicException('gateway_unavailable');
            }

            $decoded = $this->decode($profile, $response);
            $reason = $validator->validateOutput($bodyBytes, $decoded['outputItemsBytes']);
            if ($reason !== null) {
                throw new LogicException($reason);
            }

            return $decoded;
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException && in_array($error->getMessage(), GatewayModelResponse::REASON_CODES, true)
                ? $error->getMessage() : 'gateway_unavailable';
            if ($this->lifecycle !== null && $this->lifecycle['state'] === 'pending') {
                $this->lifecycle['state'] = 'uncertain';
                $this->lifecycle['reasonCode'] = $reason;
            }
            throw new LogicException($reason);
        } finally {
            $handle = null;
            $this->sending = false;
        }
    }

    private function performNative(Closure $takeHandle, int $uploadDeadline, Closure $isUploaded, Closure $failure, ?Closure $cancel, ?Closure $stopped): array
    {
        $handle = $takeHandle();
        if (! $handle instanceof CurlHandle) {
            throw new LogicException('gateway_unavailable');
        }
        $multi = curl_multi_init();
        $added = false;
        $reason = null;
        $result = null;
        try {
            if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
                throw new LogicException('gateway_unavailable');
            }
            $added = true;
            if ($this->lifecycle !== null) {
                $this->lifecycle['nativeStarted'] = true;
            }
            $running = 1;
            $completion = null;
            while ($running > 0) {
                if (! $isUploaded() && hrtime(true) >= $uploadDeadline) {
                    throw new LogicException('expired');
                }
                if ($cancel !== null) {
                    $cancellation = $cancel();
                    if ($cancellation !== null) {
                        throw new LogicException(in_array($cancellation, GatewayModelResponse::REASON_CODES, true) && $cancellation !== 'none'
                            ? $cancellation : 'gateway_channel_unavailable');
                    }
                }
                if (curl_multi_exec($multi, $running) !== CURLM_OK) {
                    throw new LogicException('gateway_unavailable');
                }
                while (($message = curl_multi_info_read($multi)) !== false) {
                    if (($message['msg'] ?? null) !== CURLMSG_DONE || ($message['handle'] ?? null) !== $handle) {
                        throw new LogicException('gateway_unavailable');
                    }
                    $completion = $message['result'];
                }
                if ($running > 0) {
                    $wait = $isUploaded() ? 0.01 : min(0.01, max(0.0, ($uploadDeadline - hrtime(true)) / 1000000000));
                    if (curl_multi_select($multi, $wait) === -1) {
                        usleep(1000);
                    }
                }
            }
            if (! is_int($completion)) {
                throw new LogicException('gateway_unavailable');
            }
            if ($completion !== CURLE_OK || ! $isUploaded()) {
                throw new LogicException($failure() ?? 'gateway_unavailable');
            }

            $result = [
                'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'errorCode' => $completion,
                'uploadedBytes' => curl_getinfo($handle, CURLINFO_SIZE_UPLOAD),
            ];
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException && in_array($error->getMessage(), GatewayModelResponse::REASON_CODES, true)
                ? $error->getMessage() : 'gateway_unavailable';
            unset($error);
        } finally {
            $weakHandle = \WeakReference::create($handle);
            $removed = $added && ($this->nativeTestRemove === null
                ? curl_multi_remove_handle($multi, $handle)
                : ($this->nativeTestRemove)($multi, $handle)) === CURLM_OK;
            unset($message);
            $handle = null;
            $multi = null;
            $destroyed = $weakHandle->get() === null;
            if (! $removed || ! $destroyed) {
                $reason = 'gateway_unavailable';
                if ($this->lifecycle !== null) {
                    $this->lifecycle['state'] = 'uncertain';
                    $this->lifecycle['reasonCode'] = $reason;
                }
            } elseif ($reason !== null) {
                if ($this->lifecycle !== null) {
                    $this->lifecycle['state'] = 'stopped';
                    $this->lifecycle['reasonCode'] = $reason;
                }
                if ($stopped !== null) {
                    $stopped($reason);
                }
            }
        }
        if ($reason !== null) {
            throw new LogicException($reason);
        }

        return $result;
    }

    private function decode(GatewayModelProfile $profile, string $bytes): array
    {
        $envelope = GatewayModelRequest::decodeJson($bytes, self::MAX_RESPONSE_BYTES);
        if (! $this->keysAllowed($envelope, ['id', 'object', 'created_at', 'status', 'model', 'output'], [
            'usage', 'error', 'incomplete_details', 'store', 'stream', 'parallel_tool_calls', 'reasoning',
            'max_output_tokens', 'text', 'tools', 'tool_choice', 'previous_response_id', 'metadata',
            'background', 'instructions', 'temperature', 'top_p', 'truncation', 'service_tier',
        ]) || ! GatewayModelResponse::providerId($envelope['id']) || $envelope['object'] !== 'response'
            || ! is_int($envelope['created_at']) || $envelope['created_at'] < 1
            || $envelope['status'] !== 'completed' || $envelope['model'] !== $profile->values()['modelId']
            || ($envelope['error'] ?? null) !== null || ($envelope['incomplete_details'] ?? null) !== null
            || ! is_array($envelope['output'])) {
            throw new LogicException('invalid_model_output');
        }
        // Only explicitly bounded, stateless native echoes are recognized.
        $expected = [
            'store' => false, 'stream' => false, 'parallel_tool_calls' => false,
            'max_output_tokens' => $profile->values()['maxOutputTokens'],
            'text' => ['format' => ['type' => 'json_object']], 'tools' => GatewayPublicCoreRequestValidator::tools(),
            'tool_choice' => 'auto', 'previous_response_id' => null, 'metadata' => new \stdClass,
            'background' => false, 'instructions' => null, 'truncation' => 'disabled',
        ];
        foreach ($expected as $key => $value) {
            if (array_key_exists($key, $envelope) && GatewayModelRequest::canonicalJson($envelope[$key]) !== GatewayModelRequest::canonicalJson($value)) {
                throw new LogicException('invalid_model_output');
            }
        }
        if (array_key_exists('reasoning', $envelope)
            && GatewayModelRequest::canonicalJson($envelope['reasoning']) !== GatewayModelRequest::canonicalJson(['effort' => 'none'])
            && GatewayModelRequest::canonicalJson($envelope['reasoning']) !== GatewayModelRequest::canonicalJson(['effort' => 'none', 'summary' => null])) {
            throw new LogicException('invalid_model_output');
        }
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $envelope) && $envelope[$key] !== null
                && ((! is_int($envelope[$key]) && ! is_float($envelope[$key]))
                    || $envelope[$key] < 0 || $envelope[$key] > ($key === 'temperature' ? 2 : 1))) {
                throw new LogicException('invalid_model_output');
            }
        }
        if (isset($envelope['service_tier']) && ! in_array($envelope['service_tier'], ['auto', 'default'], true)) {
            throw new LogicException('invalid_model_output');
        }
        $outputItemsBytes = GatewayModelRequest::canonicalJson($envelope['output']);
        GatewayModelResponse::outputItems($outputItemsBytes);
        $usage = null;
        if (($envelope['usage'] ?? null) !== null) {
            $raw = $envelope['usage'];
            if (! is_array($raw) || ! $this->keysAllowed($raw, ['input_tokens', 'output_tokens', 'total_tokens'], ['input_tokens_details', 'output_tokens_details'])) {
                throw new LogicException('invalid_model_output');
            }
            $usage = ['inputTokens' => $raw['input_tokens'], 'outputTokens' => $raw['output_tokens'], 'totalTokens' => $raw['total_tokens']];
            $reason = (new GatewayPublicCoreRequestValidator)->validateUsage($profile, $usage);
            if ($reason !== null) {
                throw new LogicException($reason);
            }
            foreach (['input_tokens_details' => ['cached_tokens', $raw['input_tokens']], 'output_tokens_details' => ['reasoning_tokens', $raw['output_tokens']]] as $key => [$detail, $limit]) {
                if (array_key_exists($key, $raw) && (! GatewayModelRequest::hasExactKeys($raw[$key], [$detail])
                    || ! is_int($raw[$key][$detail]) || $raw[$key][$detail] < 0 || $raw[$key][$detail] > $limit)) {
                    throw new LogicException('invalid_model_output');
                }
            }
        }

        return ['outputItemsBytes' => $outputItemsBytes, 'providerResponseId' => $envelope['id'], 'usage' => $usage, 'actualModel' => $envelope['model']];
    }

    private function keysAllowed(array $value, array $required, array $optional): bool
    {
        return array_diff($required, array_keys($value)) === []
            && array_diff(array_keys($value), array_merge($required, $optional)) === [];
    }

}
