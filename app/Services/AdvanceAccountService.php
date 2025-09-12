<?php

namespace App\Services;

use App\Models\AdvanceAccountTransaction;
use App\Models\User;
use App\Models\File;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class AdvanceAccountService
{
    /**
     * Получить транзакции с применением фильтров.
     *
     * @param array $filters
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getTransactions(array $filters, $perPage = 15)
    {
        $query = AdvanceAccountTransaction::query()->with(['user', 'project', 'createdBy', 'approvedBy']);
        
        // Применяем фильтры
        if (isset($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }
        
        if (isset($filters['organization_id'])) {
            $query->byOrganization($filters['organization_id']);
        }
        
        if (isset($filters['project_id'])) {
            $query->byProject($filters['project_id']);
        }
        
        if (isset($filters['type'])) {
            $query->ofType($filters['type']);
        }
        
        if (isset($filters['reporting_status'])) {
            $query->withStatus($filters['reporting_status']);
        }
        
        if (isset($filters['date_from']) && isset($filters['date_to'])) {
            $query->inPeriod($filters['date_from'], $filters['date_to']);
        }
        
        return $query->latest()->paginate($perPage);
    }

    /**
     * Создать новую транзакцию.
     *
     * @param array $data
     * @return AdvanceAccountTransaction
     * @throws Exception
     */
    public function createTransaction(array $data)
    {
        try {
            DB::beginTransaction();
            
            $user = User::findOrFail($data['user_id']);
            $amount = (float) $data['amount'];
            $type = $data['type'];
            
            // Рассчитываем новый баланс
            $newBalance = $this->calculateNewBalance($user, $type, $amount);
            
            // Создаем транзакцию
            $transaction = new AdvanceAccountTransaction();
            $transaction->fill($data);
            $transaction->balance_after = $newBalance;
            $transaction->reporting_status = AdvanceAccountTransaction::STATUS_PENDING;
            $transaction->created_by_user_id = Auth::id();
            $transaction->save();
            
            // Обновляем баланс пользователя
            $this->updateUserBalance($user, $type, $amount, $transaction);
            
            DB::commit();
            return $transaction;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create advance transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Обновить существующую транзакцию.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param array $data
     * @return AdvanceAccountTransaction
     * @throws Exception
     */
    public function updateTransaction(AdvanceAccountTransaction $transaction, array $data)
    {
        try {
            // Проверка: если изменяется сумма или тип, и статус не "pending",
            // то запрещаем изменение этих полей
            if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
                // Удаляем поля, которые нельзя изменять
                unset($data['amount']);
                unset($data['type']);
                unset($data['user_id']);
                unset($data['organization_id']);
            }
            
            $transaction->fill($data);
            $transaction->save();
            
            return $transaction;
        } catch (Exception $e) {
            Log::error('Failed to update advance transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'transaction_id' => $transaction->id,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Удалить транзакцию.
     *
     * @param AdvanceAccountTransaction $transaction
     * @return bool
     * @throws Exception
     */
    public function deleteTransaction(AdvanceAccountTransaction $transaction)
    {
        try {
            DB::beginTransaction();
            
            // Можно удалять только транзакции со статусом "pending" (в ожидании отчета)
            if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
                throw new Exception('Нельзя удалить транзакцию, по которой уже создан отчет или которая утверждена');
            }
            
            $user = $transaction->user;
            $type = $transaction->type;
            $amount = $transaction->amount;
            
            // Восстанавливаем предыдущее состояние баланса пользователя
            $this->revertUserBalanceChange($user, $type, $amount);
            
            // Удаляем транзакцию
            $transaction->delete();
            
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete advance transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'transaction_id' => $transaction->id
            ]);
            throw $e;
        }
    }

    /**
     * Отметить транзакцию как отчитанную.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param array $data
     * @return AdvanceAccountTransaction
     * @throws Exception
     */
    public function reportTransaction(AdvanceAccountTransaction $transaction, array $data)
    {
        try {
            DB::beginTransaction();
            
            // Проверяем статус транзакции
            if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_PENDING) {
                throw new Exception('По этой транзакции уже был создан отчет');
            }
            
            // Обновляем данные транзакции
            $transaction->description = $data['description'];
            $transaction->document_number = $data['document_number'];
            $transaction->document_date = $data['document_date'];
            $transaction->reporting_status = AdvanceAccountTransaction::STATUS_REPORTED;
            $transaction->reported_at = Carbon::now();
            
            // Если есть файлы, прикрепляем их
            if (isset($data['files']) && is_array($data['files'])) {
                $this->attachFilesToTransaction($transaction, $data['files']);
            }
            
            $transaction->save();
            
            // Обновляем данные пользователя
            $user = $transaction->user;
            $user->total_reported += $transaction->amount;
            $user->save();
            
            DB::commit();
            return $transaction;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to report advance transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'transaction_id' => $transaction->id,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Утвердить отчет по транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param array $data
     * @return AdvanceAccountTransaction
     * @throws Exception
     */
    public function approveTransaction(AdvanceAccountTransaction $transaction, array $data)
    {
        try {
            DB::beginTransaction();
            
            // Проверяем статус транзакции
            if ($transaction->reporting_status !== AdvanceAccountTransaction::STATUS_REPORTED) {
                throw new Exception('Утвердить можно только транзакции со статусом "Отчитана"');
            }
            
            // Обновляем данные транзакции
            $transaction->approved_at = Carbon::now();
            $transaction->approved_by_user_id = Auth::id();
            $transaction->reporting_status = AdvanceAccountTransaction::STATUS_APPROVED;
            
            $transaction->save();
            
            // Здесь можно добавить логику, если нужно что-то сделать после утверждения
            // Например, обновить баланс пользователя (если "Отчитана" не означает окончательное списание)
            
            DB::commit();
            return $transaction;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve advance transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'transaction_id' => $transaction->id,
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Прикрепить файлы к транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param array $files
     * @return AdvanceAccountTransaction
     */
    public function attachFilesToTransaction(AdvanceAccountTransaction $transaction, array $files)
    {
        // Если транзакция уже утверждена, запрещаем добавление файлов
        if ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_APPROVED) {
            throw new Exception('Нельзя добавить файлы к утвержденной транзакции');
        }

        $currentFileIds = $transaction->attachment_ids ? explode(',', $transaction->attachment_ids) : [];
        $newFileIds = [];

        foreach ($files as $fileData) {
            if ($fileData instanceof \Illuminate\Http\UploadedFile) {
                $path = $fileData->store('advance_transaction_attachments/' . $transaction->id, 'public');
                $file = new File();
                $file->filename = $fileData->getClientOriginalName();
                $file->filepath = $path;
                $file->filesize = $fileData->getSize();
                $file->filetype = $fileData->getMimeType();
                $file->uploaded_by_user_id = Auth::id();
                $file->organization_id = $transaction->organization_id;
                $file->save();
                $newFileIds[] = $file->id;
            } elseif (is_numeric($fileData)) {
                // Если передан ID существующего файла, просто добавляем его
                // (предполагается, что файл уже существует и проверен)
                $newFileIds[] = $fileData;
            }
        }

        $allFileIds = array_unique(array_merge($currentFileIds, $newFileIds));
        $transaction->attachment_ids = implode(',', $allFileIds);
        $transaction->save();

        return $transaction;
    }

    /**
     * Открепить файл от транзакции.
     *
     * @param AdvanceAccountTransaction $transaction
     * @param int $fileId
     * @return AdvanceAccountTransaction
     * @throws Exception
     */
    public function detachFileFromTransaction(AdvanceAccountTransaction $transaction, $fileId)
    {
        try {
            // Если транзакция уже утверждена, запрещаем удаление файлов
            if ($transaction->reporting_status === AdvanceAccountTransaction::STATUS_APPROVED) {
                throw new Exception('Нельзя удалить файлы из утвержденной транзакции');
            }
            
            $fileIds = [];
            if ($transaction->attachment_ids) {
                $fileIds = explode(',', $transaction->attachment_ids);
            }
            
            // Проверяем, что файл прикреплен к транзакции
            if (!in_array($fileId, $fileIds)) {
                throw new Exception('Файл не прикреплен к данной транзакции');
            }
            
            // Удаляем ID файла из списка
            $fileIds = array_diff($fileIds, [$fileId]);
            $transaction->attachment_ids = implode(',', $fileIds);
            $transaction->save();
            
            // Удаляем файл (опционально)
            // $file = File::find($fileId);
            // if ($file) {
            //     Storage::disk('public')->delete($file->filepath);
            //     $file->delete();
            // }
            
            return $transaction;
        } catch (Exception $e) {
            Log::error('Failed to detach file from transaction: ' . $e->getMessage(), [
                'exception' => $e,
                'transaction_id' => $transaction->id,
                'file_id' => $fileId
            ]);
            throw $e;
        }
    }

    /**
     * Рассчитать новый баланс пользователя после транзакции.
     *
     * @param User $user
     * @param string $type
     * @param float $amount
     * @return float
     */
    protected function calculateNewBalance(User $user, $type, $amount)
    {
        $currentBalance = (float) $user->current_balance;
        
        switch ($type) {
            case AdvanceAccountTransaction::TYPE_ISSUE:
                return $currentBalance + $amount;
            case AdvanceAccountTransaction::TYPE_EXPENSE:
                return $currentBalance - $amount;
            case AdvanceAccountTransaction::TYPE_RETURN:
                return $currentBalance - $amount;
            default:
                return $currentBalance;
        }
    }

    /**
     * Обновить баланс пользователя после создания транзакции.
     *
     * @param User $user
     * @param string $type
     * @param float $amount
     * @param AdvanceAccountTransaction $transaction
     * @return User
     */
    protected function updateUserBalance(User $user, $type, $amount, AdvanceAccountTransaction $transaction)
    {
        switch ($type) {
            case AdvanceAccountTransaction::TYPE_ISSUE:
                $user->current_balance += $amount;
                $user->total_issued += $amount;
                break;
            case AdvanceAccountTransaction::TYPE_EXPENSE:
                $user->current_balance -= $amount;
                break;
            case AdvanceAccountTransaction::TYPE_RETURN:
                $user->current_balance -= $amount;
                break;
        }
        
        $user->last_transaction_at = Carbon::now();
        $user->save();
        
        return $user;
    }

    /**
     * Откатить изменение баланса пользователя при удалении транзакции.
     *
     * @param User $user
     * @param string $type
     * @param float $amount
     * @return User
     */
    protected function revertUserBalanceChange(User $user, $type, $amount)
    {
        switch ($type) {
            case AdvanceAccountTransaction::TYPE_ISSUE:
                $user->current_balance -= $amount;
                $user->total_issued -= $amount;
                break;
            case AdvanceAccountTransaction::TYPE_EXPENSE:
                $user->current_balance += $amount;
                break;
            case AdvanceAccountTransaction::TYPE_RETURN:
                $user->current_balance += $amount;
                break;
        }
        
        $user->save();
        
        return $user;
    }

    /**
     * Получить пользователей, доступных для транзакций подотчетных средств.
     *
     * @param int $organizationId
     * @param string|null $search
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableUsers(int $organizationId, ?string $search = null)
    {
        $query = User::whereHas('organizations', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->whereHas('roleAssignments', function ($q) use ($organizationId) {
                $q->whereIn('role_slug', ['foreman', 'site_manager', 'project_manager'])
                  ->whereHas('context', function ($contextQuery) use ($organizationId) {
                      $contextQuery->where('context_type', 'organization')
                                   ->where('context_id', $organizationId);
                  })
                  ->where('is_active', true);
            })
            ->select([
                'id', 'name', 'current_balance', 'has_overdue_balance', 'position', 'avatar_path'
            ]);

        // Применяем поиск, если задан
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->get();

        // Добавляем URL аватара для каждого пользователя
        $users->each(function ($user) {
            $user->append('avatar_url');
        });

        return $users;
    }

    /**
     * Получить проекты, доступные для транзакций подотчетных средств.
     *
     * @param int $organizationId
     * @param int|null $userId
     * @param string|null $search
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableProjects(int $organizationId, ?int $userId = null, ?string $search = null)
    {
        try {
            // Базовый запрос для проектов
            $query = Project::where('organization_id', $organizationId)
                ->where('is_archived', false)
                ->select(['id', 'name', 'external_code', 'status', 'address']);

            // Если указан ID пользователя, фильтруем по проектам, назначенным этому пользователю
            if ($userId) {
                $query->whereHas('users', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                });
            }

            // Применяем поиск, если задан
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('address', 'like', "%{$search}%")
                      ->orWhere('external_code', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('name')->get();
        } catch (\Throwable $e) {
            Log::error('[AdvanceAccountService@getAvailableProjects] Exception caught: ' . $e->getMessage(), [
                'organizationId' => $organizationId,
                'userId' => $userId,
                'search' => $search,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString() // Осторожно, может быть большим
            ]);
            // Перевыбрасываем, чтобы сохранить поведение ошибки 500 и чтобы Laravel мог ее обработать стандартно
            throw $e; 
        }
    }
} 