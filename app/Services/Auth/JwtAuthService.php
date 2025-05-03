<?php

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\User;
use App\Repositories\OrganizationRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
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
        try {
            Auth::shouldUse($guard);
            
            if (!$token = Auth::attempt($loginDTO->toArray())) {
                return [
                    'success' => false,
                    'message' => 'Неверный email или пароль',
                    'status_code' => 401
                ];
            }

            $user = Auth::user();
            
            // Обновляем информацию о последнем входе
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ]);

            return [
                'success' => true,
                'token' => $token,
                'user' => $user,
                'status_code' => 200
            ];
        } catch (JWTException $e) {
            Log::error('JWT Auth Error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message' => 'Ошибка создания токена',
                'status_code' => 500
            ];
        }
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
                return [
                    'success' => false,
                    'message' => 'Пользователь не аутентифицирован',
                    'status_code' => 401
                ];
            }

            // Загружаем дополнительные данные
            $user = $this->userRepository->findWithRoles($user->id);

            return [
                'success' => true,
                'user' => $user,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            return [
                'success' => false,
                'message' => 'Токен просрочен',
                'status_code' => 401
            ];
        } catch (TokenInvalidException $e) {
            return [
                'success' => false,
                'message' => 'Недействительный токен',
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            return [
                'success' => false,
                'message' => 'Токен отсутствует',
                'status_code' => 401
            ];
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

            return [
                'success' => true,
                'token' => $token,
                'status_code' => 200
            ];
        } catch (TokenExpiredException $e) {
            return [
                'success' => false,
                'message' => 'Токен просрочен и не может быть обновлен',
                'status_code' => 401
            ];
        } catch (JWTException $e) {
            Log::error('JWT Refresh Error', ['error' => $e->getMessage()]);
            
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
     * @return array
     */
    public function logout(string $guard): array
    {
        try {
            Auth::shouldUse($guard);
            Auth::logout();

            return [
                'success' => true,
                'message' => 'Выход выполнен успешно',
                'status_code' => 200
            ];
        } catch (JWTException $e) {
            Log::error('JWT Logout Error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при выходе',
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
        try {
            // Начинаем транзакцию
            \DB::beginTransaction();
            
            // Создаем организацию
            $organization = $this->organizationRepository->create($registerDTO->getOrganizationData());
            
            // Подготавливаем и создаем пользователя
            $userData = $registerDTO->getUserData();
            $userData['password'] = bcrypt($userData['password']);
            $userData['current_organization_id'] = $organization->id;
            $userData['user_type'] = 'organization_admin';
            
            $user = $this->userRepository->create($userData);
            
            // Связываем пользователя с организацией как владельца
            $this->userRepository->attachToOrganization($user->id, $organization->id, true, true);
            
            // Находим или создаем роль администратора организации
            $role = \App\Models\Role::firstOrCreate(
                ['slug' => 'organization_admin', 'organization_id' => null],
                ['name' => 'Администратор организации', 'type' => 'system']
            );
            
            // Назначаем роль пользователю в контексте организации
            $this->userRepository->assignRole($user->id, $role->id, $organization->id);
            
            // Фиксируем транзакцию
            \DB::commit();
            
            // Аутентифицируем пользователя
            Auth::shouldUse('api_landing');
            $token = Auth::login($user);
            
            return [
                'success' => true,
                'token' => $token,
                'user' => $user,
                'organization' => $organization,
                'status_code' => 201
            ];
        } catch (\Exception $e) {
            \DB::rollBack();
            Log::error('Registration Error', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при регистрации: ' . $e->getMessage(),
                'status_code' => 500
            ];
        }
    }
} 