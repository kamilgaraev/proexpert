<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore\Transport;

use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use LogicException;
use Socket;
use Throwable;

final class AuthenticatedPublicCoreChannel
{
    public const SCHEMA_VERSION = 'public-core-channel/1';

    public const MAX_FRAME_BYTES = 1048576;

    public const CLEANUP_GRACE_MS = 250;

    public const COMMANDS = [
        'hello', 'readiness', 'open_or_resume', 'execute_owned', 'lookup_owned',
        'result', 'dispatch', 'check_binding', 'binding', 'authorize_write',
        'write_authorized', 'upload_complete', 'uploaded', 'abort',
    ];

    private int $sentSequence = 0;

    private int $receivedSequence = 0;

    private ?array $verifiedPeer = null;

    private bool $closed = false;

    private readonly int $deadline;

    private readonly int $expiresAt;

    private ?array $cleanupBinding = null;

    private ?int $cleanupDeadline = null;

    private ?array $gatewayTransfer = null;

    private bool $publishingLifecycle = false;

    private ?string $bootstrapTicket = null;

    private function __construct(
        private readonly Socket $socket,
        private readonly array $expectedPeer,
        private string $reference,
        int $deadlineMs,
    ) {
        self::requireNative();
        if (! GatewayModelRequest::hasExactKeys($expectedPeer, ['uid', 'gid', 'pid'])
            || ! is_int($expectedPeer['uid']) || $expectedPeer['uid'] < 0
            || ! is_int($expectedPeer['gid']) || $expectedPeer['gid'] < 0
            || ($expectedPeer['pid'] !== null && (! is_int($expectedPeer['pid']) || $expectedPeer['pid'] < 1))
            || $deadlineMs < 50 || $deadlineMs > 30000) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $this->deadline = hrtime(true) + $deadlineMs * 1000000;
        $this->expiresAt = time() + (int) ceil($deadlineMs / 1000);
        if (! socket_set_nonblock($socket)
            || ! socket_set_option($socket, SOL_SOCKET, constant('SO_PASSCRED'), 1)) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    public static function isNativeAvailable(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && extension_loaded('sockets')
            && function_exists('posix_geteuid') && function_exists('posix_getegid')
            && defined('SO_PASSCRED') && defined('SCM_CREDENTIALS')
            && function_exists('socket_recvmsg') && function_exists('socket_sendmsg');
    }

    private static function requireNative(): void
    {
        if (! self::isNativeAvailable()) {
            throw new LogicException('gateway_identity_unavailable');
        }
    }

    public static function listen(string $socketPath): Socket
    {
        self::requireNative();
        self::checkDirectory($socketPath, posix_geteuid(), posix_getegid());
        if (@lstat($socketPath) !== false) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (! $socket instanceof Socket) {
            throw new LogicException('gateway_channel_unavailable');
        }
        try {
            if (! socket_set_option($socket, SOL_SOCKET, constant('SO_PASSCRED'), 1)
                || ! socket_bind($socket, $socketPath) || ! chmod($socketPath, 0660)
                || ! socket_listen($socket, 8) || ! socket_set_nonblock($socket)) {
                throw new LogicException('gateway_channel_unavailable');
            }

            return $socket;
        } catch (Throwable $error) {
            socket_close($socket);
            throw $error;
        }
    }

    public static function connect(string $socketPath, array $expectedPeer, int $deadlineMs = 15000): self
    {
        self::requireNative();
        if (! GatewayModelRequest::hasExactKeys($expectedPeer, ['uid', 'gid', 'pid'])
            || ! is_int($expectedPeer['uid']) || ! is_int($expectedPeer['gid'])) {
            throw new LogicException('gateway_identity_unavailable');
        }
        self::checkDirectory($socketPath, $expectedPeer['uid'], $expectedPeer['gid']);
        $stat = @lstat($socketPath);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0140000
            || $stat['uid'] !== $expectedPeer['uid'] || $stat['gid'] !== $expectedPeer['gid']
            || ($stat['mode'] & 0007) !== 0) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (! $socket instanceof Socket) {
            throw new LogicException('gateway_channel_unavailable');
        }
        try {
            $channel = new self($socket, $expectedPeer, '', $deadlineMs);
            if (! @socket_connect($socket, $socketPath)) {
                $pending = array_map('constant', ['SOCKET_EINPROGRESS', 'SOCKET_EALREADY', 'SOCKET_EAGAIN']);
                if (! in_array(socket_last_error($socket), $pending, true)) {
                    throw new LogicException('gateway_channel_unavailable');
                }
                $channel->wait(false);
                if (socket_get_option($socket, SOL_SOCKET, SO_ERROR) !== 0) {
                    throw new LogicException('gateway_channel_unavailable');
                }
            }
            $hello = $channel->receive();
            if ($hello['command'] !== 'hello' || $hello['requestRef'] !== null
                || $hello['attemptRef'] !== null || $hello['payload'] !== []) {
                throw new LogicException('gateway_channel_unavailable');
            }
            $channel->send('hello', null, null, [], min($channel->expiresAt, $hello['expiresAt']));

            return $channel;
        } catch (Throwable $error) {
            socket_close($socket);
            throw $error;
        }
    }

    public static function accept(Socket $listener, array $expectedPeer, int $deadlineMs = 15000): self
    {
        self::requireNative();
        if ($deadlineMs < 50 || $deadlineMs > 30000) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $read = [$listener];
        $write = $except = [];
        if (@socket_select($read, $write, $except, intdiv($deadlineMs, 1000), ($deadlineMs % 1000) * 1000) !== 1) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $socket = @socket_accept($listener);
        if (! $socket instanceof Socket) {
            throw new LogicException('gateway_channel_unavailable');
        }
        try {
            $channel = new self($socket, $expectedPeer, 'channel:'.bin2hex(random_bytes(16)), $deadlineMs);
            $channel->send('hello', null, null, [], $channel->expiresAt);
            $hello = $channel->receive();
            if ($hello['command'] !== 'hello' || $hello['requestRef'] !== null
                || $hello['attemptRef'] !== null || $hello['payload'] !== []) {
                throw new LogicException('gateway_channel_unavailable');
            }

            return $channel;
        } catch (Throwable $error) {
            socket_close($socket);
            throw $error;
        }
    }

    private static function checkDirectory(string $socketPath, int $uid, int $gid): void
    {
        $directory = dirname($socketPath);
        $stat = @lstat($directory);
        if (! str_starts_with($socketPath, '/') || strlen($socketPath) > 100 || str_contains($socketPath, "\0")
            || realpath($directory) !== $directory || ! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0027) !== 0
            || $stat['uid'] !== $uid || $stat['gid'] !== $gid) {
            throw new LogicException('gateway_identity_unavailable');
        }
    }

    public function send(string $command, ?string $requestRef, ?string $attemptRef, array $payload, int $expiresAt): void
    {
        $frame = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'channelRef' => $this->reference,
            'sequence' => $this->sentSequence + 1,
            'command' => $command,
            'requestRef' => $requestRef,
            'attemptRef' => $attemptRef,
            'expiresAt' => $expiresAt,
            'payload' => $payload,
        ];
        $this->validateFrame($frame, $this->sentSequence + 1);
        $this->validateGatewayFrame($frame, true);
        if ($command !== 'hello' && $this->verifiedPeer === null) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $bytes = GatewayModelRequest::canonicalJson($frame);
        if (strlen($bytes) > self::MAX_FRAME_BYTES) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $wire = pack('N', strlen($bytes)).$bytes;
        while ($wire !== '') {
            $this->wait(false);
            $sent = @socket_sendmsg($this->socket, ['iov' => [$wire]], 0);
            if (! is_int($sent) || $sent < 1) {
                throw new LogicException('gateway_channel_unavailable');
            }
            $wire = substr($wire, $sent);
        }
        $this->sentSequence++;
        $this->recordGatewayFrame($frame, true);
    }

    public function receive(): array
    {
        $length = unpack('Nlength', $this->readBytes(4))['length'];
        if ($length < 2 || $length > self::MAX_FRAME_BYTES) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $bytes = $this->readBytes($length);
        try {
            $frame = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($frame) || $bytes !== GatewayModelRequest::canonicalJson($frame)) {
                throw new LogicException('gateway_channel_unavailable');
            }
            if ($this->reference === '') {
                if (($frame['command'] ?? null) !== 'hello' || ! GatewayModelRequest::isReference($frame['channelRef'] ?? null)) {
                    throw new LogicException('gateway_channel_unavailable');
                }
                $this->reference = $frame['channelRef'];
            }
            $this->validateFrame($frame, $this->receivedSequence + 1);
            $this->validateGatewayFrame($frame, false);
            $this->receivedSequence++;
            $this->recordGatewayFrame($frame, false);

            return $frame;
        } catch (Throwable) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    private function validateFrame(array $frame, int $sequence): void
    {
        $cleanup = $this->cleanupBinding !== null;
        if (! GatewayModelRequest::hasExactKeys($frame, [
            'schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload',
        ]) || $frame['schemaVersion'] !== self::SCHEMA_VERSION
            || ! GatewayModelRequest::isReference($frame['channelRef']) || $frame['channelRef'] !== $this->reference
            || $frame['sequence'] !== $sequence || $sequence > 1024
            || ! in_array($frame['command'], self::COMMANDS, true)
            || (($sequence === 1) !== ($frame['command'] === 'hello'))
            || ($frame['requestRef'] !== null && ! GatewayModelRequest::isReference($frame['requestRef']))
            || ($frame['attemptRef'] !== null && ! GatewayModelRequest::isReference($frame['attemptRef']))
            || ! is_int($frame['expiresAt']) || (! $cleanup && ($frame['expiresAt'] <= time() || $frame['expiresAt'] > $this->expiresAt))
            || ! is_array($frame['payload'])) {
            throw new LogicException('gateway_channel_unavailable');
        }
        if ($frame['command'] === 'hello') {
            if ($frame['requestRef'] !== null || $frame['attemptRef'] !== null || $frame['payload'] !== []) {
                throw new LogicException('gateway_channel_unavailable');
            }
        } elseif ($frame['requestRef'] === null || $frame['attemptRef'] === null) {
            $payload = $frame['payload'];
            $schema = $payload['schemaVersion'] ?? null;
            $keys = match ($schema) {
                'public-core-app-viewer-ticket-check/1' => ['schemaVersion', 'viewerTicketRef'],
                'public-core-app-viewer-ticket-binding/1' => ['schemaVersion', 'viewerTicketRef', 'currentViewer'],
                'public-core-app-viewer-ticket-denial/1' => ['schemaVersion', 'viewerTicketRef', 'reasonCode'],
                default => [],
            };
            if ($frame['requestRef'] !== null || $frame['attemptRef'] !== null || $keys === []
                || ! GatewayModelRequest::hasExactKeys($payload, $keys) || ! GatewayModelRequest::isReference($payload['viewerTicketRef'])
                || ($schema === 'public-core-app-viewer-ticket-check/1' ? $frame['command'] !== 'check_binding'
                    : ($frame['command'] !== 'binding' || $this->bootstrapTicket !== $payload['viewerTicketRef']))
                || ($schema === 'public-core-app-viewer-ticket-binding/1' && ! self::validViewer($payload['currentViewer']))
                || ($schema === 'public-core-app-viewer-ticket-denial/1' && (! in_array($payload['reasonCode'], GatewayModelResponse::REASON_CODES, true)
                    || $payload['reasonCode'] === 'none'))) {
                throw new LogicException('gateway_channel_unavailable');
            }
            $this->bootstrapTicket = $schema === 'public-core-app-viewer-ticket-check/1' ? $payload['viewerTicketRef'] : null;
        }
        if ($cleanup && ($frame['command'] !== 'abort' || $frame['requestRef'] !== $this->cleanupBinding['requestRef']
            || $frame['attemptRef'] !== $this->cleanupBinding['attemptRef'] || $frame['expiresAt'] !== $this->cleanupBinding['outerExpiry']
            || ! GatewayModelRequest::hasExactKeys($frame['payload'], ['schemaVersion', 'binding', 'reasonCode'])
            || ! in_array($frame['payload']['schemaVersion'], ['public-core-gateway-upload-cancel/1', 'public-core-gateway-upload-stopped/1'], true)
            || ! in_array($frame['payload']['reasonCode'], GatewayModelResponse::REASON_CODES, true) || $frame['payload']['reasonCode'] === 'none'
            || GatewayModelRequest::canonicalJson($frame['payload']['binding']) !== GatewayModelRequest::canonicalJson($this->cleanupBinding['binding']))) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    private static function validViewer(mixed $viewer): bool
    {
        if (! GatewayModelRequest::hasExactKeys($viewer, ['authorized', 'viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'])
            || $viewer['authorized'] !== true) {
            return false;
        }
        foreach (['viewerRef', 'organizationRef', 'authorizationRevision', 'policyRevision'] as $key) {
            if (! is_string($viewer[$key]) || $viewer[$key] === '' || strlen($viewer[$key]) > 160 || preg_match('//u', $viewer[$key]) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function dispatchGatewayRequest(GatewayModelRequest $request, int $outerExpiry): void
    {
        $this->bindGatewayTransfer($request, $outerExpiry, 'processor');
        $this->send('dispatch', $request->requestRef, $request->attemptRef, $request->values(), $outerExpiry);
    }

    public function acceptGatewayRequest(): GatewayModelRequest
    {
        $frame = $this->receive();
        if ($frame['command'] !== 'dispatch') {
            throw new LogicException('gateway_channel_unavailable');
        }
        $request = GatewayModelRequest::fromArray($frame['payload']);
        if ($frame['requestRef'] !== $request->requestRef || $frame['attemptRef'] !== $request->attemptRef) {
            throw new LogicException('receipt_changed');
        }
        $this->bindGatewayTransfer($request, $frame['expiresAt'], 'gateway');

        return $request;
    }

    private function bindGatewayTransfer(GatewayModelRequest $request, int $outerExpiry, string $role): void
    {
        if ($this->gatewayTransfer !== null || $this->cleanupBinding !== null || $outerExpiry <= time()
            || $outerExpiry > min($request->expiresAt, $this->expiresAt) || $this->verifiedPeer === null) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $this->gatewayTransfer = ['role' => $role, 'request' => $request, 'outerExpiry' => $outerExpiry,
            'genuineDeadline' => min($this->deadline, hrtime(true) + (int) floor(($outerExpiry - microtime(true)) * 1000000000)),
            'phase' => 'pending', 'control' => null, 'event' => null, 'eventSequence' => null, 'reasonCode' => 'none'];
    }

    public function gatewayOuterExpiry(): int
    {
        if ($this->gatewayTransfer === null) {
            throw new LogicException('gateway_channel_unavailable');
        }

        return $this->gatewayTransfer['outerExpiry'];
    }

    public function gatewayDeadline(): int
    {
        if ($this->gatewayTransfer === null) {
            throw new LogicException('gateway_channel_unavailable');
        }

        return $this->gatewayTransfer['genuineDeadline'];
    }

    public function receiveGatewayControl(): array
    {
        if (($this->gatewayTransfer['role'] ?? null) !== 'processor') {
            throw new LogicException('gateway_channel_unavailable');
        }

        return $this->receive();
    }

    public function gatewayLifecycle(): array
    {
        if (($this->gatewayTransfer['role'] ?? null) !== 'processor') {
            throw new LogicException('gateway_channel_unavailable');
        }
        $request = $this->gatewayTransfer['request'];

        return ['schemaVersion' => 'public-core-processor-gateway-lifecycle/1', 'channelRef' => $this->reference,
            'binding' => $request->binding(), 'state' => $this->gatewayTransfer['event'] ?? 'pending',
            'eventSequence' => $this->gatewayTransfer['eventSequence'], 'reasonCode' => $this->gatewayTransfer['reasonCode'],
            'bodyLength' => strlen($request->bodyBytes)];
    }

    public function publishGatewayLifecycle(GatewayPublicCoreHttpSender $sender, string $event): void
    {
        if (($this->gatewayTransfer['role'] ?? null) !== 'gateway' || ! in_array($event, ['uploaded', 'stopped'], true)
            || $this->gatewayTransfer['event'] !== null) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $request = $this->gatewayTransfer['request'];
        $state = $sender->nativeLifecycle($request, $this->reference);
        if ($state['state'] !== $event || $state['nativeStarted'] !== true || $state['bodyLength'] !== strlen($request->bodyBytes)) {
            throw new LogicException('gateway_channel_unavailable');
        }
        if ($event === 'stopped') {
            $this->beginCleanup($request, $this->gatewayTransfer['outerExpiry']);
        }
        $this->publishingLifecycle = true;
        try {
            $this->send($event === 'uploaded' ? 'upload_complete' : 'abort', $request->requestRef, $request->attemptRef,
                $event === 'uploaded' ? ['projectionDigest' => $request->projectionDigest, 'bodyLength' => $state['bodyLength']]
                    : ['schemaVersion' => 'public-core-gateway-upload-stopped/1', 'binding' => $request->binding(), 'reasonCode' => $state['reasonCode']],
                $this->gatewayTransfer['outerExpiry']);
            $this->gatewayTransfer['event'] = $event;
        } finally {
            $this->publishingLifecycle = false;
        }
    }

    private function validateGatewayFrame(array $frame, bool $outgoing): void
    {
        if ($this->gatewayTransfer === null) {
            return;
        }
        $request = $this->gatewayTransfer['request'];
        if ($frame['requestRef'] !== $request->requestRef || $frame['attemptRef'] !== $request->attemptRef
            || $frame['expiresAt'] !== $this->gatewayTransfer['outerExpiry']) {
            throw new LogicException('receipt_changed');
        }
        $gatewaySide = ($this->gatewayTransfer['role'] === 'gateway') === $outgoing;
        $command = $frame['command'];
        $payload = $frame['payload'];
        $phase = $this->gatewayTransfer['phase'];
        $validator = new GatewayPublicCoreRequestValidator;
        if ($gatewaySide) {
            $valid = match ($command) {
                'check_binding', 'authorize_write' => $phase === 'pending' && $this->gatewayTransfer['control'] === null
                    && GatewayModelRequest::hasExactKeys($payload, ['projectionDigest', 'profileFingerprint'])
                    && $payload['projectionDigest'] === $request->projectionDigest && $payload['profileFingerprint'] === $request->profileFingerprint,
                'upload_complete' => in_array($phase, ['authorized', 'cancelling'], true) && $this->gatewayTransfer['event'] === null
                    && GatewayModelRequest::hasExactKeys($payload, ['projectionDigest', 'bodyLength'])
                    && $payload['projectionDigest'] === $request->projectionDigest && $payload['bodyLength'] === strlen($request->bodyBytes),
                'abort' => in_array($phase, ['authorized', 'cancelling'], true) && $this->gatewayTransfer['event'] === null
                    && GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'binding', 'reasonCode'])
                    && $payload['schemaVersion'] === 'public-core-gateway-upload-stopped/1'
                    && $validator->validateBinding($request, $payload['binding']) === null
                    && in_array($payload['reasonCode'], GatewayModelResponse::REASON_CODES, true) && $payload['reasonCode'] !== 'none',
                'result' => in_array($phase, ['pending', 'denied', 'authorized', 'cancelling', 'uploaded'], true) && $this->gatewayTransfer['control'] === null
                    && GatewayModelResponse::fromArray($payload)->requestRef === $request->requestRef
                    && GatewayModelResponse::fromArray($payload)->attemptRef === $request->attemptRef
                    && GatewayModelResponse::fromArray($payload)->profileFingerprint === $request->profileFingerprint
                    && ($phase === 'uploaded' || GatewayModelResponse::fromArray($payload)->status !== 'completed'),
                default => false,
            };
            if ($outgoing && in_array($command, ['upload_complete', 'abort'], true) && ! $this->publishingLifecycle) {
                $valid = false;
            }
        } else {
            $valid = match ($command) {
                'dispatch' => $phase === 'pending' && $outgoing && $this->sentSequence === 1,
                'binding' => $this->gatewayTransfer['control'] === 'check_binding',
                'write_authorized' => $this->gatewayTransfer['control'] === 'authorize_write',
                'uploaded' => $phase === 'uploaded' && GatewayModelRequest::hasExactKeys($payload, ['projectionDigest', 'bodyLength'])
                    && $payload['projectionDigest'] === $request->projectionDigest && $payload['bodyLength'] === strlen($request->bodyBytes),
                'abort' => $phase === 'authorized' && GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'binding', 'reasonCode'])
                    && $payload['schemaVersion'] === 'public-core-gateway-upload-cancel/1'
                    && $validator->validateBinding($request, $payload['binding']) === null
                    && in_array($payload['reasonCode'], GatewayModelResponse::REASON_CODES, true) && $payload['reasonCode'] !== 'none',
                default => false,
            };
        }
        if (! $valid) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    private function recordGatewayFrame(array $frame, bool $outgoing): void
    {
        if ($this->gatewayTransfer === null) {
            return;
        }
        $gatewaySide = ($this->gatewayTransfer['role'] === 'gateway') === $outgoing;
        $command = $frame['command'];
        if ($gatewaySide && in_array($command, ['check_binding', 'authorize_write'], true)) {
            $this->gatewayTransfer['control'] = $command;
        } elseif (! $gatewaySide && in_array($command, ['binding', 'write_authorized'], true)) {
            $this->gatewayTransfer['control'] = null;
            if ($command === 'write_authorized') {
                $request = $this->gatewayTransfer['request'];
                $payload = $frame['payload'];
                $valid = GatewayModelRequest::hasExactKeys($payload, ['schemaVersion', 'binding', 'uploadTimeoutMs'])
                    && $payload['schemaVersion'] === 'public-core-gateway-upload-grant/1' && is_int($payload['uploadTimeoutMs'])
                    && $payload['uploadTimeoutMs'] > 0 && $payload['uploadTimeoutMs'] <= intdiv(PHP_INT_MAX, 1000000)
                    && (new GatewayPublicCoreRequestValidator)->validateBinding($request, $payload['binding']) === null;
                $this->gatewayTransfer['phase'] = $valid ? 'authorized' : 'denied';
            }
        } elseif ($gatewaySide && in_array($command, ['upload_complete', 'abort'], true)) {
            $this->gatewayTransfer['event'] = $command === 'upload_complete' ? 'uploaded' : 'stopped';
            $this->gatewayTransfer['phase'] = $this->gatewayTransfer['event'];
            $this->gatewayTransfer['eventSequence'] = $frame['sequence'];
            $this->gatewayTransfer['reasonCode'] = $command === 'abort' ? $frame['payload']['reasonCode'] : 'none';
        } elseif (! $gatewaySide && $command === 'abort') {
            $this->gatewayTransfer['phase'] = 'cancelling';
        } elseif ($gatewaySide && $command === 'result') {
            $this->gatewayTransfer['phase'] = 'result';
        }
    }

    private function readBytes(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->wait(true);
            $message = [
                'buffer_size' => min($length - strlen($bytes), 65536),
                'controllen' => socket_cmsg_space(SOL_SOCKET, constant('SCM_CREDENTIALS')) + socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1),
            ];
            $received = @socket_recvmsg($this->socket, $message, 0);
            if (! is_int($received) || $received < 1) {
                throw new LogicException('gateway_channel_unavailable');
            }
            $this->validateCredentials($message);
            $part = implode('', $message['iov'] ?? []);
            if (strlen($part) !== $received) {
                throw new LogicException('gateway_channel_unavailable');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    private function validateCredentials(array $message): void
    {
        $controls = $message['control'] ?? [];
        foreach ($controls as $control) {
            if (($control['level'] ?? null) === SOL_SOCKET && ($control['type'] ?? null) === SCM_RIGHTS) {
                foreach ($control['data'] ?? [] as $descriptor) {
                    if ($descriptor instanceof Socket) {
                        socket_close($descriptor);
                    } elseif (is_resource($descriptor)) {
                        fclose($descriptor);
                    }
                }
            }
        }
        if (($message['flags'] ?? 0) & (MSG_CTRUNC | MSG_TRUNC)
            || count($controls) !== 1 || ($controls[0]['level'] ?? null) !== SOL_SOCKET
            || ($controls[0]['type'] ?? null) !== constant('SCM_CREDENTIALS')) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $peer = $controls[0]['data'] ?? null;
        if (! GatewayModelRequest::hasExactKeys($peer, ['pid', 'uid', 'gid'])
            || ! is_int($peer['pid']) || $peer['pid'] < 1
            || $peer['uid'] !== $this->expectedPeer['uid'] || $peer['gid'] !== $this->expectedPeer['gid']
            || ($this->expectedPeer['pid'] !== null && $peer['pid'] !== $this->expectedPeer['pid'])
            || ($this->verifiedPeer !== null && $peer !== $this->verifiedPeer)) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $this->verifiedPeer = $peer;
    }

    private function wait(bool $reading): void
    {
        $remaining = ($this->cleanupDeadline ?? $this->deadline) - hrtime(true);
        if ($this->closed || $remaining <= 0) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $read = $reading ? [$this->socket] : [];
        $write = $reading ? [] : [$this->socket];
        $except = [$this->socket];
        $microseconds = (int) ceil($remaining / 1000);
        if (@socket_select($read, $write, $except, intdiv($microseconds, 1000000), $microseconds % 1000000) !== 1
            || $except !== []) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    public function assertAlive(): void
    {
        if ($this->closed || hrtime(true) >= $this->deadline || $this->verifiedPeer === null) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $read = $except = [$this->socket];
        $write = [];
        if (@socket_select($read, $write, $except, 0, 0) !== 0) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    public function poll(): ?array
    {
        if ($this->closed || hrtime(true) >= ($this->cleanupDeadline ?? $this->deadline)) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $read = $except = [$this->socket];
        $write = [];
        $ready = @socket_select($read, $write, $except, 0, 0);
        if ($ready === false || $except !== []) {
            throw new LogicException('gateway_channel_unavailable');
        }

        return $ready === 0 ? null : $this->receive();
    }

    public function beginCleanup(GatewayModelRequest $request, int $originalOuterExpiry): void
    {
        if ($this->closed || $this->verifiedPeer === null || $this->cleanupBinding !== null
            || $originalOuterExpiry < 1 || $originalOuterExpiry > $request->expiresAt
            || $this->gatewayTransfer === null || $this->gatewayTransfer['request'] !== $request
            || $this->gatewayTransfer['outerExpiry'] !== $originalOuterExpiry
            || ! in_array($this->gatewayTransfer['phase'], ['authorized', 'cancelling'], true)
            || hrtime(true) >= $this->gatewayTransfer['genuineDeadline'] + self::CLEANUP_GRACE_MS * 1000000) {
            throw new LogicException('gateway_channel_unavailable');
        }
        $this->cleanupBinding = [
            'requestRef' => $request->requestRef,
            'attemptRef' => $request->attemptRef,
            'outerExpiry' => $originalOuterExpiry,
            'binding' => $request->binding(),
        ];
        $this->cleanupDeadline = min(hrtime(true) + self::CLEANUP_GRACE_MS * 1000000,
            $this->gatewayTransfer['genuineDeadline'] + self::CLEANUP_GRACE_MS * 1000000);
    }

    public function isCleanupOnly(): bool
    {
        return $this->cleanupBinding !== null;
    }

    public function peer(): array
    {
        if ($this->verifiedPeer === null || $this->closed) {
            throw new LogicException('gateway_identity_unavailable');
        }

        return $this->verifiedPeer;
    }

    public function channelRef(): string
    {
        return $this->reference;
    }

    public function deadlineExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function close(): void
    {
        if (! $this->closed) {
            socket_close($this->socket);
            $this->closed = true;
        }
    }
}
