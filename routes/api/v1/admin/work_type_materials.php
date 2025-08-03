<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\WorkTypeMaterialController;

// Маршруты для управления материалами, привязанными к видам работ
// Префикс /work-types/{work_type} будет добавлен при подключении этого файла

Route::get('/materials', [WorkTypeMaterialController::class, 'indexForWorkType'])
    ->name('work-type-materials.index');

Route::post('/materials', [WorkTypeMaterialController::class, 'storeOrUpdateForWorkType'])
    ->name('work-type-materials.storeOrUpdate');

Route::delete('/materials/{material}', [WorkTypeMaterialController::class, 'destroyForWorkType'])
    ->name('work-type-materials.destroy');

// Роут для получения предложенных материалов
Route::get('/suggest-materials', [WorkTypeMaterialController::class, 'getSuggestedMaterialsForWorkType'])
   ->name('work-type-materials.suggest'); 

// Роут для получения предложенных материалов (не CRUD, а вспомогательный)
// Route::get('/suggested-materials', [WorkTypeMaterialController::class, 'getSuggestedMaterialsForWorkType'])
//    ->name('materials.suggested'); 
// Этот роут лучше вынести отдельно или сделать GET-запросом к основному ресурсу WorkTypes 
// с параметром action=suggest-materials, если контроллер будет расширен.
// Пока что метод getSuggestedMaterials есть в сервисе и может быть вызван другими частями приложения.