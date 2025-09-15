<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сервис управления контентом сайтов
 */
class ContentManagementService
{
    /**
     * Создать новый блок контента
     */
    public function createBlock(HoldingSite $site, array $data, User $creator): SiteContentBlock
    {
        // Автоматически определяем порядок сортировки
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = $site->contentBlocks()->max('sort_order') + 1;
        }

        // Генерируем уникальный ключ блока
        if (!isset($data['block_key'])) {
            $data['block_key'] = $this->generateBlockKey($site, $data['block_type']);
        }

        return SiteContentBlock::create([
            'holding_site_id' => $site->id,
            'block_type' => $data['block_type'],
            'block_key' => $data['block_key'],
            'title' => $data['title'] ?? '',
            'content' => $data['content'] ?? [],
            'settings' => $data['settings'] ?? [],
            'sort_order' => $data['sort_order'],
            'is_active' => $data['is_active'] ?? true,
            'status' => 'draft',
            'created_by_user_id' => $creator->id,
        ]);
    }

    /**
     * Обновить блок контента
     */
    public function updateBlock(SiteContentBlock $block, array $data, User $user): bool
    {
        $updateData = array_filter([
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'settings' => $data['settings'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'updated_by_user_id' => $user->id,
        ], fn($value) => $value !== null);

        $updated = $block->update($updateData);
        
        if ($updated) {
            $block->holdingSite->clearCache();
        }

        return $updated;
    }

    /**
     * Изменить порядок блоков
     */
    public function reorderBlocks(HoldingSite $site, array $blockOrder, User $user): bool
    {
        return DB::transaction(function () use ($site, $blockOrder, $user) {
            foreach ($blockOrder as $index => $blockId) {
                SiteContentBlock::where('id', $blockId)
                    ->where('holding_site_id', $site->id)
                    ->update([
                        'sort_order' => $index + 1,
                        'updated_by_user_id' => $user->id,
                    ]);
            }

            $site->clearCache();
            return true;
        });
    }

    /**
     * Опубликовать блок
     */
    public function publishBlock(SiteContentBlock $block, User $user): bool
    {
        return $block->publish($user);
    }

    /**
     * Дублировать блок
     */
    public function duplicateBlock(SiteContentBlock $block, User $user): SiteContentBlock
    {
        $newBlock = $block->replicate();
        $newBlock->block_key = $this->generateBlockKey($block->holdingSite, $block->block_type);
        $newBlock->title = $block->title . ' (копия)';
        $newBlock->sort_order = $block->holdingSite->contentBlocks()->max('sort_order') + 1;
        $newBlock->status = 'draft';
        $newBlock->published_at = null;
        $newBlock->created_by_user_id = $user->id;
        $newBlock->updated_by_user_id = $user->id;
        $newBlock->save();

        return $newBlock;
    }

    /**
     * Удалить блок
     */
    public function deleteBlock(SiteContentBlock $block, User $user): bool
    {
        $site = $block->holdingSite;
        $deleted = $block->delete();
        
        if ($deleted) {
            $site->clearCache();
        }

        return $deleted;
    }

    /**
     * Получить блоки для редактирования
     */
    public function getBlocksForEditing(HoldingSite $site): array
    {
        return $site->contentBlocks()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'type' => $block->block_type,
                    'key' => $block->block_key,
                    'title' => $block->title,
                    'content' => $block->content,
                    'settings' => $block->settings,
                    'sort_order' => $block->sort_order,
                    'is_active' => $block->is_active,
                    'status' => $block->status,
                    'published_at' => $block->published_at,
                    'schema' => SiteContentBlock::getContentSchema($block->block_type),
                    'can_delete' => !in_array($block->block_type, ['hero', 'contacts']), // Некоторые блоки нельзя удалять
                ];
            })
            ->toArray();
    }

    /**
     * Получить проекты организации для блока проектов
     */
    public function getOrganizationProjects(HoldingSite $site, int $limit = 6): array
    {
        $organization = $site->organizationGroup->parentOrganization;
        
        $projects = $organization->projects()
            ->where('is_archived', false)
            ->where('status', 'completed')
            ->orderBy('end_date', 'desc')
            ->limit($limit)
            ->get(['id', 'name', 'description', 'address', 'budget_amount', 'end_date']);

        return $projects->map(function ($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'address' => $project->address,
                'budget' => $project->budget_amount,
                'completed_date' => $project->end_date?->format('Y-m-d'),
                // TODO: Добавить изображения проектов в будущем
                'image' => null,
            ];
        })->toArray();
    }

    /**
     * Генерировать уникальный ключ блока
     */
    private function generateBlockKey(HoldingSite $site, string $blockType): string
    {
        $baseKey = $blockType;
        $counter = 1;
        $key = $baseKey;

        while ($site->contentBlocks()->where('block_key', $key)->exists()) {
            $counter++;
            $key = $baseKey . '_' . $counter;
        }

        return $key;
    }

    /**
     * Валидировать данные блока
     */
    public function validateBlockData(string $blockType, array $content): array
    {
        $schema = SiteContentBlock::getContentSchema($blockType);
        $errors = [];

        foreach ($schema as $field => $rules) {
            if ($rules['required'] && empty($content[$field])) {
                $errors[] = "Поле '{$field}' обязательно для заполнения";
            }

            if (!empty($content[$field])) {
                $errors = array_merge($errors, $this->validateFieldType($field, $content[$field], $rules['type']));
            }
        }

        return $errors;
    }

    /**
     * Валидировать тип поля
     */
    private function validateFieldType(string $field, $value, string $type): array
    {
        $errors = [];

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Поле '{$field}' должно содержать корректный email";
                }
                break;
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[] = "Поле '{$field}' должно содержать корректный URL";
                }
                break;
            case 'number':
                if (!is_numeric($value)) {
                    $errors[] = "Поле '{$field}' должно быть числом";
                }
                break;
        }

        return $errors;
    }
}
