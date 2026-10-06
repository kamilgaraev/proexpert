<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore\Transport;

use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use LogicException;
use Socket;
use Throwable;

final class AuthenticatedPublicCoreChannel
{
    public const SCHEMA_VERSION = 'public-core-channel/1';

    public const MAX_FRAME_BYTES = 1048576;

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
            $this->receivedSequence++;

            return $frame;
        } catch (Throwable) {
            throw new LogicException('gateway_channel_unavailable');
        }
    }

    private function validateFrame(array $frame, int $sequence): void
    {
        if (! GatewayModelRequest::hasExactKeys($frame, [
            'schemaVersion', 'channelRef', 'sequence', 'command', 'requestRef', 'attemptRef', 'expiresAt', 'payload',
        ]) || $frame['schemaVersion'] !== self::SCHEMA_VERSION
            || ! GatewayModelRequest::isReference($frame['channelRef']) || $frame['channelRef'] !== $this->reference
            || $frame['sequence'] !== $sequence || $sequence > 1024
            || ! in_array($frame['command'], self::COMMANDS, true)
            || (($sequence === 1) !== ($frame['command'] === 'hello'))
            || ($frame['requestRef'] !== null && ! GatewayModelRequest::isReference($frame['requestRef']))
            || ($frame['attemptRef'] !== null && ! GatewayModelRequest::isReference($frame['attemptRef']))
            || ! is_int($frame['expiresAt']) || $frame['expiresAt'] <= time() || $frame['expiresAt'] > $this->expiresAt
            || ! is_array($frame['payload'])) {
            throw new LogicException('gateway_channel_unavailable');
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
        $remaining = $this->deadline - hrtime(true);
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
