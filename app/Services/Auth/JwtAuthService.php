<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use App\Repositories\OrganizationRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\LogService;
use App\Services\PerformanceMonitor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class JwtAuthService
{
    protected UserRepositoryInterface $userRepository;
    protected OrganizationRepositoryInterface $organizationRepository;

    /**
     * Конструктор сервиса аутентификации.
     *
     * @param UserRepositoryInterface $userRepository
     * @param OrganizationRepositoryInterface $organizationRepository
     */
    public function __construct(
        UserRepositoryInterface $userRepository,
        OrganizationRepositoryInterface $organizationRepository
    ) {
        $this->userRepository = $userRepository;
        $this->organizationRepository = $organizationRepository;
    }

    /**
     * Аутентификация пользователя и получение токена JWT.
     *
     * @param LoginDTO $loginDTO
     * @param string $guard
     * @return array
     */
    public function authenticate(LoginDTO $loginDTO, string $guard): array
    {
        Log::info('[JwtAuthService] authenticate method entered.');
        // Убираем PerformanceMonitor временно для диагностики
        // return PerformanceMonitor::measure('auth.login', function() use ($loginDTO, $guard) { 
            $logContext = [];
            try {
                Log::info('[JwtAuthService] Inside main try block.');
                Auth::shouldUse($guard);
                $logContext = [
                    'email' => $loginDTO->email,
                    'guard' => $guard,
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent')
                ];
                Log::info('[JwtAuthService] Log context prepared.', $logContext);

                $credentials = $loginDTO->toArray();
                Log::info('[JwtAuthService] Credentials prepared, entering inner try block.');
                try {
                    Log::info('[JwtAuthService] Trying Auth::validate.', ['email' => $credentials['email'] ?? 'N/A', 'guard' => $guard]);
                    if (!Auth::validate($credentials)) {
                        Log::warning('[JwtAuthService] Auth::validate FAILED.', ['email' => $credentials['email'] ?? 'N/A', 'guard' => $guard]);
                        LogService::authLog('login_failed', array_merge($logContext, ['reason' => 'credentials_invalid']));
                        return ['success' => false, 'message' => 'Неверный email или пароль', 'status_code' => 401];
                    }
                    Log::info('[JwtAuthService] Auth::validate passed. Before Auth::getLastAttempted.');

                    $user = Auth::getLastAttempted();
                    Log::info('[JwtAuthService] User retrieved.', ['user_id' => $user?->id]);
                    $user->update([
                        'last_login_at' => now(),
                        'last_login_ip' => request()->ip(),
                    ]);
                    Log::info('[JwtAuthService] User last login updated.');

                    // Определяем ID организации (пока просто первая)
                    $userOrganizations = $user->organizations()->pluck('organizations.id')->toArray(); // <-- Указываем таблицу organizations.id
                    Log::info('[JwtAuthService] User organizations IDs.', ['user_id' => $user->id, 'organization_ids' => $userOrganizations]); // Логируем все организации
                    
                    // Явно выбираем ID организации перед first()
                    $organizationId = $user->organizations()->select('organizations.id')->first()?->id; // <-- Указываем таблицу и выбираем organisations.id
                    Log::info('[JwtAuthService] Organization ID determined for token (using first).', ['user_id' => $user->id, 'selected_org_id' => $organizationId]); // Логируем выбранную

                    // Устанавливаем current_organization_id для объекта User, который вернется в контроллер
                    // Это важно для корректной работы Gate в контроллере
                    if ($organizationId && $user->current_organization_id !== $organizationId) {
                         $user->current_organization_id = $organizationId; // Просто присваиваем свойству для текущего запроса
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was different).', ['user_id' => $user->id, 'org_id' => $organizationId]); // Логируем установку
                    } elseif (!$user->current_organization_id && $organizationId) {
                        // Если был null, а мы нашли ID
                        $user->current_organization_id = $organizationId;
                         Log::info('[JwtAuthService] User object\'s current_organization_id set (was null).', ['user_id' => $user->id, 'org_id' => $organizationId]); // Логируем установку
                    } else {
                         Log::info('[JwtAuthService] User object\'s current_organization_id not changed.', ['user_id' => $user->id, 'existing_org_id' => $user->current_organization_id, 'determined_org_id' => $organizationId]); // Логируем, если не меняли
                    }

                    // Генерируем токен
                    $customClaims = ['organization_id' => $organizationId];
                    $token = JWTAuth::claims($customClaims)->fromUser($user);
                    Log::info('[JwtAuthService] JWT token generated.');

                    LogService::authLog('login_success', array_merge($logContext, ['user_id' => $user->id, 'organization_id' => $organizationId]));
                    // Возвращаем $user с установленным (надеемся) current_organization_id
                    return ['success' => true, 'token' => $token, 'user' => $user, 'status_code' => 200];

                } catch (JWTException $e) {
                    Log::error('[JwtAuthService] JWTException caught.', ['error' => $e->getMessage()]);
                    $errorContext = array_merge($logContext, [
                        'action' => 'login_jwt_error',
                        'guard' => $guard,
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode()
                    ]);
                    if (isset($loginDTO)) { try { $errorContext['email'] = $loginDTO->email; } catch (\Exception $ex) {} }
                    
                    LogService::exception($e, $errorContext);
                    
                    return [
                        'success' => false,
                        'message' => 'Ошибка создания токена JWT',
                        'status_code' => 500
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('[JwtAuthService] Throwable caught in outer catch block.', ['error' => $e->getMessage()]); 
                $errorContext = array_merge($logContext, [
                    'action' => 'login_unexpected_error',
                    'guard' => $guard,
                    'error_message' => $e->getMessage(),
                ]);
                if (isset($loginDTO)) { try { $errorContext['email'] = $loginDTO->email; } catch (\Exception $ex) {} }

                // Заменяем LogService::exception на стандартный Log::error с полным стеком
                // Log::info('[JwtAuthService] Before calling LogService::exception in outer catch.');
                // LogService::exception($e, $errorContext); 
                // Log::info('[JwtAuthService] After calling LogService::exception in outer catch.');
                Log::error('[JwtAuthService] Unexpected Authentication Error', [
                    'context' => $errorContext,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString() // Логируем полный стек
                ]);

                return [
                    'success' => false,
                    'message' => 'Внутренняя ошибка сервера при аутентификации.',
                    'status_code' => 500
                ];
            }
        // }); // Конец PerformanceMonitor
    }

    /**
     * Получение информации о текущем пользователе.
     *
     * @param string $guard
     * @return array
     */
    public function me(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            $user = Auth::user();

            if (!$user) {
                LogService::authLog('profile_access_failed', [
                    'guard' => $guard,
                    'reason' => 'not_authenticated',
                    'ip' => request()->ip()
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Пользователь не аутентифицирован',
                    'status_code' => 401
                ];
            }

            // Загружаем дополнительные данные
            $user = $this->userRepository->findWithRoles($user->id);
            
            LogService::authLog('profile_access', [
                'user_id' => $user->id,
                'guard' => $guard,
                'ip' => request()->ip()
            ]);

            return [
                'success' => true,
                'user' => $user,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            LogService::authLog('profile_access_failed', [
                'guard' => $guard,
                'reason' => 'token_expired',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'Токен просрочен',
                'status_code' => 401
            ];
        } catch (TokenInvalidException $e) {
            LogService::authLog('profile_access_failed', [
                'guard' => $guard,
                'reason' => 'token_invalid',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'Недействительный токен',
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'profile_access',
                'guard' => $guard,
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'Токен отсутствует',
                'status_code' => 401
            ];
        }
    }

    /**
     * Получение ID текущей организации из JWT токена.
     *
     * @return int|null ID организации или null, если токен не содержит organization_id.
     */
    public function getCurrentOrganizationId(): ?int
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            // Предполагаем, что ID организации хранится в claim 'organization_id'
            return $payload->get('organization_id');
        } catch (JWTException $e) {
            // Обработка случаев, когда токен невалиден, отсутствует или не содержит нужного claim
            LogService::exception($e, [
                'action' => 'get_current_organization_id',
                'ip' => request()->ip()
            ]);
            return null;
        }
    }

    /**
     * Обновление токена JWT.
     *
     * @param string $guard
     * @return array
     */
    public function refresh(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            $token = Auth::refresh();
            
            // Получаем пользователя после обновления токена
            $user = Auth::user();
            
            LogService::authLog('token_refresh', [
                'user_id' => $user ? $user->id : null,
                'guard' => $guard,
                'ip' => request()->ip()
            ]);

            return [
                'success' => true,
                'token' => $token,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            LogService::authLog('token_refresh_failed', [
                'guard' => $guard,
                'reason' => 'token_expired',
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'Токен просрочен и не может быть обновлен',
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'token_refresh',
                'guard' => $guard,
                'ip' => request()->ip()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка обновления токена',
                'status_code' => 500
            ];
        }
    }

    /**
     * Выход пользователя (инвалидация токена JWT).
     *
     * @param string $guard
     * @param bool $logAction Записывать ли стандартное событие logout в лог
     * @return array
     */
    public function logout(string $guard, bool $logAction = true): array
    {
        try {
            Auth::shouldUse($guard);
            
            $user = Auth::user();
            $userId = $user ? $user->id : null;
            $token = Auth::getToken();
            
            if ($token) {
                JWTAuth::invalidate($token);
                Auth::logout(true); // true - очистить пользовательские данные
                
                if (request()->hasSession()) {
                    request()->session()->invalidate();
                    request()->session()->regenerateToken();
                }
                
                if ($logAction) { // <-- Проверяем флаг перед логированием
                    LogService::authLog('logout', [
                        'user_id' => $userId,
                        'guard' => $guard,
                        'ip' => request()->ip(),
                        'token_blacklisted' => true
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'Выход выполнен успешно', // Это сообщение не будет видно при вызове с Gate::denies
                    'status_code' => 200
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Токен не найден',
                'status_code' => 401
            ];
            
        } catch (JWTException $e) {
            LogService::exception($e, [
                'action' => 'logout',
                'guard' => $guard,
                'ip' => request()->ip(),
                'error_message' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при выходе: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Регистрация нового пользователя (только для API лендинга).
     *
     * @param RegisterDTO $registerDTO
     * @return array
     */
    public function register(RegisterDTO $registerDTO): array
    {
        return PerformanceMonitor::measure('auth.registration', function() use ($registerDTO) {
            $logContext = [];
            
            try {
                // Используем геттеры через магический метод __get
                $logContext = [
                    'email' => $registerDTO->email,
                    'organization_name' => $registerDTO->organizationName,
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent')
                ];
                
                // Начинаем транзакцию
                \DB::beginTransaction();
                
                // Создаем организацию
                $organization = $this->organizationRepository->create($registerDTO->getOrganizationData());
                
                // Подготавливаем и создаем пользователя
                $userData = $registerDTO->getUserData();
                $userData['password'] = bcrypt($userData['password']);
                $userData['current_organization_id'] = $organization->id;
                $userData['user_type'] = 'organization_owner';
                
                $user = $this->userRepository->create($userData);
                
                // Связываем пользователя с организацией как владельца
                $this->userRepository->attachToOrganization($user->id, $organization->id, true, true);
                
                // Находим или создаем роль ВЛАДЕЛЬЦА организации
                $role = \App\Models\Role::firstOrCreate(
                    ['slug' => 'organization_owner', 'organization_id' => null],
                    ['name' => 'Владелец организации', 'type' => 'system']
                );
                
                // Назначаем роль (которая 'organization_owner') пользователю в контексте организации
                $this->userRepository->assignRole($user->id, $role->id, $organization->id);
                
                // Фиксируем транзакцию
                \DB::commit();
                
                // Аутентифицируем пользователя
                Auth::shouldUse('api_landing');
                $token = Auth::login($user);
                
                LogService::authLog('registration', array_merge($logContext, [
                    'user_id' => $user->id,
                    'organization_id' => $organization->id,
                    'status' => 'success'
                ]));
                
                return [
                    'success' => true,
                    'token' => $token,
                    'user' => $user,
                    'organization' => $organization,
                    'status_code' => 201
                ];
            } catch (\Exception $e) {
                \DB::rollBack();
                
                // Добавляем базовую информацию к контексту
                $errorContext = array_merge($logContext, [
                    'action' => 'registration',
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ]);
                
                LogService::exception($e, $errorContext);
                
                return [
                    'success' => false,
                    'message' => 'Ошибка при регистрации: ' . $e->getMessage(),
                    'status_code' => 500
                ];
            }
        });
    }
} 