<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\ContentManagementService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SiteBlocksController extends Controller
{
    private ContentManagementService $contentService;

    public function __construct(ContentManagementService $contentService)
    {
        $this->contentService = $contentService;
    }

    /**
     * Получить все блоки сайта
     */
    public function index(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра блоков'
                ], 403);
            }

            $blocks = $this->contentService->getBlocksForEditing($site);

            return response()->json([
                'success' => true,
                'data' => $blocks
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site blocks', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения блоков'
            ], 500);
        }
    }

    /**
     * Создать новый блок
     */
    public function store(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для создания блока'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'block_type' => 'required|string|in:hero,about,services,projects,team,contacts,testimonials,gallery,news,custom',
                'title' => 'nullable|string|max:255',
                'content' => 'required|array',
                'settings' => 'nullable|array',
                'sort_order' => 'nullable|integer',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Валидация контента блока
            $contentErrors = $this->contentService->validateBlockData(
                $request->block_type,
                $request->content ?? []
            );

            if (!empty($contentErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации контента',
                    'errors' => $contentErrors
                ], 422);
            }

            $block = $this->contentService->createBlock($site, $request->all(), $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $block->id,
                    'type' => $block->block_type,
                    'key' => $block->block_key,
                    'title' => $block->title,
                    'content' => $block->content,
                    'settings' => $block->settings,
                    'sort_order' => $block->sort_order,
                    'is_active' => $block->is_active,
                    'status' => $block->status,
                    'created_at' => $block->created_at
                ],
                'message' => 'Блок успешно создан'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating site block', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания блока'
            ], 500);
        }
    }

    /**
     * Обновить блок
     */
    public function update(Request $request, int $holdingId, int $siteId, int $blockId): JsonResponse
    {
        try {
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $site = $block->holdingSite;
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования блока'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'content' => 'sometimes|required|array',
                'settings' => 'nullable|array',
                'sort_order' => 'nullable|integer',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Валидация контента если он обновляется
            if ($request->has('content')) {
                $contentErrors = $this->contentService->validateBlockData(
                    $block->block_type,
                    $request->content
                );

                if (!empty($contentErrors)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ошибки валидации контента',
                        'errors' => $contentErrors
                    ], 422);
                }
            }

            $updated = $this->contentService->updateBlock($block, $request->all(), $user);

            if ($updated) {
                return response()->json([
                    'success' => true,
                    'message' => 'Блок обновлен'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обновить блок'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error updating site block', [
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления блока'
            ], 500);
        }
    }

    /**
     * Опубликовать блок
     */
    public function publish(Request $request, int $holdingId, int $siteId, int $blockId): JsonResponse
    {
        try {
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $user = Auth::user();
            $published = $this->contentService->publishBlock($block, $user);

            return response()->json([
                'success' => true,
                'message' => 'Блок опубликован',
                'data' => [
                    'status' => $block->status,
                    'published_at' => $block->published_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error publishing site block', [
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Дублировать блок
     */
    public function duplicate(Request $request, int $holdingId, int $siteId, int $blockId): JsonResponse
    {
        try {
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $site = $block->holdingSite;
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для дублирования блока'
                ], 403);
            }

            $newBlock = $this->contentService->duplicateBlock($block, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $newBlock->id,
                    'type' => $newBlock->block_type,
                    'key' => $newBlock->block_key,
                    'title' => $newBlock->title,
                    'sort_order' => $newBlock->sort_order,
                    'status' => $newBlock->status
                ],
                'message' => 'Блок продублирован'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error duplicating site block', [
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка дублирования блока'
            ], 500);
        }
    }

    /**
     * Удалить блок
     */
    public function destroy(Request $request, int $holdingId, int $siteId, int $blockId): JsonResponse
    {
        try {
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $siteId)
                ->firstOrFail();

            $site = $block->holdingSite;
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для удаления блока'
                ], 403);
            }

            $deleted = $this->contentService->deleteBlock($block, $user);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Блок удален'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить блок'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting site block', [
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления блока'
            ], 500);
        }
    }

    // === МЕТОДЫ ДЛЯ РАБОТЫ С ОДНИМ ЛЕНДИНГОМ НА ХОЛДИНГ ===

    /**
     * Получить все блоки лендинга холдинга
     */
    public function indexForHolding(Request $request, int $holdingId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для просмотра блоков'
                ], 403);
            }

            $blocks = $this->contentService->getBlocksForEditing($site);

            return response()->json([
                'success' => true,
                'data' => $blocks
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting holding landing blocks', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения блоков лендинга'
            ], 500);
        }
    }

    /**
     * Создать новый блок для лендинга холдинга
     */
    public function storeForHolding(Request $request, int $holdingId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для создания блока'
                ], 403);
            }

            return $this->createBlockForSite($site, $request, $user);

        } catch (\Exception $e) {
            Log::error('Error creating holding landing block', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка создания блока лендинга'
            ], 500);
        }
    }

    /**
     * Обновить блок лендинга холдинга
     */
    public function updateForHolding(Request $request, int $holdingId, int $blockId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для редактирования блока'
                ], 403);
            }

            return $this->updateBlockData($block, $request, $user);

        } catch (\Exception $e) {
            Log::error('Error updating holding landing block', [
                'holding_id' => $holdingId,
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обновления блока лендинга'
            ], 500);
        }
    }

    /**
     * Опубликовать блок лендинга холдинга
     */
    public function publishForHolding(Request $request, int $holdingId, int $blockId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            $published = $this->contentService->publishBlock($block, $user);

            return response()->json([
                'success' => true,
                'message' => 'Блок опубликован',
                'data' => [
                    'status' => $block->status,
                    'published_at' => $block->published_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error publishing holding landing block', [
                'holding_id' => $holdingId,
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Дублировать блок лендинга холдинга
     */
    public function duplicateForHolding(Request $request, int $holdingId, int $blockId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для дублирования блока'
                ], 403);
            }

            $newBlock = $this->contentService->duplicateBlock($block, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $newBlock->id,
                    'type' => $newBlock->block_type,
                    'key' => $newBlock->block_key,
                    'title' => $newBlock->title,
                    'sort_order' => $newBlock->sort_order,
                    'status' => $newBlock->status
                ],
                'message' => 'Блок продублирован'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error duplicating holding landing block', [
                'holding_id' => $holdingId,
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка дублирования блока лендинга'
            ], 500);
        }
    }

    /**
     * Удалить блок лендинга холдинга
     */
    public function destroyForHolding(Request $request, int $holdingId, int $blockId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $block = SiteContentBlock::where('id', $blockId)
                ->where('holding_site_id', $site->id)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для удаления блока'
                ], 403);
            }

            $deleted = $this->contentService->deleteBlock($block, $user);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Блок удален'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить блок'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting holding landing block', [
                'holding_id' => $holdingId,
                'block_id' => $blockId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка удаления блока лендинга'
            ], 500);
        }
    }

    /**
     * Изменить порядок блоков лендинга холдинга (drag & drop как в Тильде)
     */
    public function reorderForHolding(Request $request, int $holdingId): JsonResponse
    {
        try {
            $site = $this->getHoldingLanding($holdingId);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для изменения порядка блоков'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'block_order' => 'required|array',
                'block_order.*' => 'integer|exists:site_content_blocks,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $reordered = $this->contentService->reorderBlocks($site, $request->block_order, $user);

            if ($reordered) {
                return response()->json([
                    'success' => true,
                    'message' => 'Порядок блоков изменен (drag & drop)'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось изменить порядок блоков'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error reordering holding landing blocks', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения порядка блоков лендинга'
            ], 500);
        }
    }

    // === ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ===

    /**
     * Получить лендинг холдинга
     */
    private function getHoldingLanding(int $holdingId): HoldingSite
    {
        return HoldingSite::where('organization_group_id', $holdingId)->firstOrFail();
    }

    /**
     * Создать блок для сайта (переиспользуемый метод)
     */
    private function createBlockForSite(HoldingSite $site, Request $request, $user): JsonResponse
    {
        // Валидация данных
        $validator = Validator::make($request->all(), [
            'block_type' => 'required|string|in:hero,about,services,projects,team,contacts,testimonials,gallery,news,custom',
            'title' => 'nullable|string|max:255',
            'content' => 'required|array',
            'settings' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибки валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Валидация контента блока
        $contentErrors = $this->contentService->validateBlockData(
            $request->block_type,
            $request->content ?? []
        );

        if (!empty($contentErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибки валидации контента',
                'errors' => $contentErrors
            ], 422);
        }

        $block = $this->contentService->createBlock($site, $request->all(), $user);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $block->id,
                'type' => $block->block_type,
                'key' => $block->block_key,
                'title' => $block->title,
                'content' => $block->content,
                'settings' => $block->settings,
                'sort_order' => $block->sort_order,
                'is_active' => $block->is_active,
                'status' => $block->status,
                'created_at' => $block->created_at
            ],
            'message' => 'Блок успешно создан'
        ], 201);
    }

    /**
     * Обновить данные блока (переиспользуемый метод)
     */
    private function updateBlockData(SiteContentBlock $block, Request $request, $user): JsonResponse
    {
        // Валидация данных
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'content' => 'sometimes|required|array',
            'settings' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибки валидации',
                'errors' => $validator->errors()
            ], 422);
        }

        // Валидация контента если он обновляется
        if ($request->has('content')) {
            $contentErrors = $this->contentService->validateBlockData(
                $block->block_type,
                $request->content
            );

            if (!empty($contentErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации контента',
                    'errors' => $contentErrors
                ], 422);
            }
        }

        $updated = $this->contentService->updateBlock($block, $request->all(), $user);

        if ($updated) {
            return response()->json([
                'success' => true,
                'message' => 'Блок обновлен'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Не удалось обновить блок'
            ], 500);
        }
    }

    /**
     * Изменить порядок блоков
     */
    public function reorder(Request $request, int $holdingId, int $siteId): JsonResponse
    {
        try {
            $site = HoldingSite::where('id', $siteId)
                ->where('organization_group_id', $holdingId)
                ->firstOrFail();

            $user = Auth::user();
            if (!$site->canUserEdit($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав для изменения порядка блоков'
                ], 403);
            }

            // Валидация данных
            $validator = Validator::make($request->all(), [
                'block_order' => 'required|array',
                'block_order.*' => 'integer|exists:site_content_blocks,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ошибки валидации',
                    'errors' => $validator->errors()
                ], 422);
            }

            $reordered = $this->contentService->reorderBlocks($site, $request->block_order, $user);

            if ($reordered) {
                return response()->json([
                    'success' => true,
                    'message' => 'Порядок блоков изменен'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось изменить порядок блоков'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Error reordering site blocks', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка изменения порядка блоков'
            ], 500);
        }
    }
}
