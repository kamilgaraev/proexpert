<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway;

use App\BusinessModules\Features\AIAssistant\Services\Loop\AssistantModelAction;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use Closure;
use CurlHandle;
use LogicException;
use Throwable;

final class GatewayPublicCoreHttpSender
{
    public const ENDPOINT = 'https://api.timeweb.ai/v1/chat/completions';

    public const MAX_RESPONSE_BYTES = 262144;

    public function __construct(
        private readonly ?Closure $credentialReader = null,
        private readonly ?Closure $executor = null,
    ) {}

    public function send(GatewayModelProfile $profile, string $bodyBytes, ?Closure $beforeWrite = null, ?Closure $uploaded = null): array
    {
        $handle = null;
        try {
            if (! $profile->isActualProfile() || $profile->values()['endpoint'] !== self::ENDPOINT
                || $profile->values()['apiMethod'] !== 'chat_completions') {
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
            $callbackReason = null;
            $headerBytes = 0;
            $length = strlen($bodyBytes);
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
                CURLOPT_XFERINFOFUNCTION => static function (CurlHandle $curl, float $downloadTotal, float $downloadNow, float $uploadTotal, float $uploadNow) use ($length, $uploaded, &$uploadReported, &$callbackReason): int {
                    if (! $uploadReported && $uploadTotal >= $length && $uploadNow >= $length) {
                        $uploadReported = true;
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
            $beforeWrite();
            if ($this->executor !== null) {
                $result = ($this->executor)($handle, $options);
            } else {
                curl_exec($handle);
                $result = [
                    'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'errorCode' => curl_errno($handle),
                    'uploadedBytes' => curl_getinfo($handle, CURLINFO_SIZE_UPLOAD),
                ];
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

            return $this->decode($profile, $response);
        } catch (Throwable $error) {
            $reason = $error instanceof LogicException && in_array($error->getMessage(), GatewayModelResponse::REASON_CODES, true)
                ? $error->getMessage() : 'gateway_unavailable';
            throw new LogicException($reason);
        } finally {
            if ($handle instanceof CurlHandle) {
                curl_close($handle);
            }
        }
    }

    private function decode(GatewayModelProfile $profile, string $bytes): array
    {
        $envelope = $this->decodeJson($bytes);
        if (! $this->keysAllowed($envelope, ['id', 'object', 'created', 'model', 'choices'], ['usage', 'system_fingerprint', 'service_tier'])
            || ! is_string($envelope['id']) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $envelope['id']) !== 1
            || $envelope['object'] !== 'chat.completion' || ! is_int($envelope['created']) || $envelope['created'] < 1
            || $envelope['model'] !== $profile->values()['modelId']
            || ! is_array($envelope['choices']) || ! array_is_list($envelope['choices']) || count($envelope['choices']) !== 1) {
            throw new LogicException('invalid_model_output');
        }
        foreach (['system_fingerprint', 'service_tier'] as $key) {
            if (isset($envelope[$key]) && (! is_string($envelope[$key]) || strlen($envelope[$key]) > 128 || preg_match('//u', $envelope[$key]) !== 1)) {
                throw new LogicException('invalid_model_output');
            }
        }
        $choice = $envelope['choices'][0];
        if (! is_array($choice) || ! $this->keysAllowed($choice, ['index', 'message', 'finish_reason'], ['logprobs'])
            || $choice['index'] !== 0 || $choice['finish_reason'] !== 'stop'
            || ($choice['logprobs'] ?? null) !== null || ! is_array($choice['message'])) {
            throw new LogicException('invalid_model_output');
        }
        $message = $choice['message'];
        if (! $this->keysAllowed($message, ['role', 'content'], ['refusal', 'annotations'])
            || $message['role'] !== 'assistant' || ($message['refusal'] ?? null) !== null
            || ($message['annotations'] ?? []) !== [] || ! is_string($message['content'])
            || $message['content'] === '' || strlen($message['content']) > 131072 || str_contains($message['content'], "\0")) {
            throw new LogicException('invalid_model_output');
        }
        try {
            $action = AssistantModelAction::parse($this->decodeJson($message['content']));
        } catch (Throwable) {
            throw new LogicException('invalid_model_output');
        }
        $actionBytes = GatewayModelRequest::canonicalJson($action->values());
        $usage = null;
        if (($envelope['usage'] ?? null) !== null) {
            $raw = $envelope['usage'];
            if (! is_array($raw) || ! $this->keysAllowed($raw, ['prompt_tokens', 'completion_tokens', 'total_tokens'], ['prompt_tokens_details', 'completion_tokens_details'])) {
                throw new LogicException('invalid_model_output');
            }
            foreach (['prompt_tokens_details' => ['cached_tokens', 'audio_tokens'], 'completion_tokens_details' => ['reasoning_tokens', 'audio_tokens', 'accepted_prediction_tokens', 'rejected_prediction_tokens']] as $key => $allowed) {
                if (isset($raw[$key])) {
                    if (! is_array($raw[$key]) || ! $this->keysAllowed($raw[$key], [], $allowed)) {
                        throw new LogicException('invalid_model_output');
                    }
                    foreach ($raw[$key] as $count) {
                        if (! is_int($count) || $count < 0) {
                            throw new LogicException('invalid_model_output');
                        }
                    }
                }
            }
            $usage = ['inputTokens' => $raw['prompt_tokens'], 'outputTokens' => $raw['completion_tokens'], 'totalTokens' => $raw['total_tokens']];
        }
        $reason = (new GatewayPublicCoreRequestValidator)->validateUsage($profile, $usage);
        if ($reason !== null) {
            throw new LogicException($reason);
        }

        return ['actionBytes' => $actionBytes, 'usage' => $usage];
    }

    private function keysAllowed(array $value, array $required, array $optional): bool
    {
        return array_diff($required, array_keys($value)) === []
            && array_diff(array_keys($value), array_merge($required, $optional)) === [];
    }

    private function decodeJson(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_RESPONSE_BYTES || preg_match('//u', $bytes) !== 1 || str_contains($bytes, "\0")) {
            throw new LogicException('invalid_model_output');
        }
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new LogicException('invalid_model_output');
        }
        if (! is_array($value)) {
            throw new LogicException('invalid_model_output');
        }
        $stack = [];
        for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
            $char = $bytes[$index];
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'key' => true, 'seen' => []];
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
            } elseif ($char === ',' && $stack !== [] && $stack[count($stack) - 1]['object']) {
                $stack[count($stack) - 1]['key'] = true;
            } elseif ($char === '"') {
                $start = $index;
                while (++$index < $length) {
                    if ($bytes[$index] === '\\') {
                        $index++;
                    } elseif ($bytes[$index] === '"') {
                        break;
                    }
                }
                $last = count($stack) - 1;
                if ($last >= 0 && $stack[$last]['object'] && $stack[$last]['key']) {
                    $key = json_decode(substr($bytes, $start, $index - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    if (isset($stack[$last]['seen'][$key])) {
                        throw new LogicException('invalid_model_output');
                    }
                    $stack[$last]['seen'][$key] = true;
                    $stack[$last]['key'] = false;
                }
            }
        }

        return $value;
    }
}
