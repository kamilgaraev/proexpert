<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiReportsDownloadController extends Controller
{
    public function download(Request $request, string $token): StreamedResponse
    {
        try {
            // Расшифровываем токен
            $data = $this->decryptToken($token);

            // Проверяем срок действия токена
            if (now()->timestamp > $data['expires_at']) {
                Log::warning('AI Report download token expired', [
                    'token' => substr($token, 0, 20) . '...',
                    'expired_at' => date('Y-m-d H:i:s', $data['expires_at']),
                ]);
                abort(410, 'Ссылка на скачивание истекла');
            }

            // Проверяем, что пользователь имеет доступ к этой организации
            $user = $request->user();
            if ($user->current_organization_id !== $data['organization_id']) {
                Log::warning('AI Report download access denied', [
                    'user_org' => $user->current_organization_id,
                    'file_org' => $data['organization_id'],
                    'path' => $data['s3_path'],
                ]);
                abort(403, 'Доступ запрещен');
            }

            // Генерируем presigned URL
            $presignedUrl = $this->generatePresignedUrl($data['s3_path']);

            Log::info('AI Report download initiated', [
                'organization_id' => $data['organization_id'],
                'path' => $data['s3_path'],
                'user_id' => $user->id,
            ]);

            // Делаем redirect на presigned URL
            return response()->stream(function () use ($presignedUrl) {
                // Используем curl для стриминга файла
                $ch = curl_init($presignedUrl);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_exec($ch);
                curl_close($ch);
            }, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . basename($data['s3_path']) . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::warning('AI Report download invalid token', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
            abort(400, 'Неверная ссылка на скачивание');
        } catch (\Exception $e) {
            Log::error('AI Report download error', [
                'token' => substr($token, 0, 20) . '...',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Ошибка при скачивании файла');
        }
    }

    /**
     * Расшифровывает токен и возвращает данные
     */
    protected function decryptToken(string $token): array
    {
        $encrypted = base64_decode($token);
        return decrypt($encrypted);
    }

    /**
     * Генерирует presigned URL для файла
     */
    protected function generatePresignedUrl(string $s3Path): string
    {
        try {
            $config = config('filesystems.disks.s3');
            
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $config['region'] ?? 'ru-central1',
                'endpoint' => $config['endpoint'] ?? 'https://storage.yandexcloud.net',
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $config['bucket'] ?? 'prohelper-storage',
                'Key' => $s3Path,
            ]);

            $request = $s3Client->createPresignedRequest($cmd, '+15 minutes');
            $presignedUrl = (string) $request->getUri();

            Log::info('Generated presigned URL for AI report download', [
                'path' => $s3Path,
            ]);

            return $presignedUrl;

        } catch (\Exception $e) {
            Log::error('Failed to generate presigned URL for AI report', [
                'path' => $s3Path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
