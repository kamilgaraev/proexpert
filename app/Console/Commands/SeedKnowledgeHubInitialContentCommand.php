<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleStatus;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class SeedKnowledgeHubInitialContentCommand extends Command
{
    protected $signature = 'knowledge-hub:seed-initial-content {--dry-run}';

    protected $description = 'Создает стартовую структуру базы знаний для ЛК, админки и мобильного приложения.';

    public function handle(): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->info('Будут созданы или обновлены категории: '.count($this->categories()));
            $this->info('Будут созданы или обновлены статьи: '.count($this->articles()));

            return SymfonyCommand::SUCCESS;
        }

        $categoryIds = [];
        foreach ($this->categories() as $category) {
            $model = KnowledgeCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
            $categoryIds[$category['slug']] = $model->id;
        }

        $articleIds = [];
        foreach ($this->articles() as $article) {
            $parentSlug = $article['parent_slug'] ?? null;
            unset($article['parent_slug']);

            $article['category_id'] = $categoryIds[$article['category_slug']] ?? null;
            unset($article['category_slug']);

            if ($parentSlug !== null) {
                $article['parent_id'] = $articleIds[$parentSlug] ?? KnowledgeArticle::query()
                    ->where('slug', $parentSlug)
                    ->value('id');
            }

            $model = KnowledgeArticle::query()->updateOrCreate(
                ['slug' => $article['slug']],
                $article,
            );
            $articleIds[$article['slug']] = $model->id;
        }

        $this->info('Стартовая база знаний обновлена.');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return [
            [
                'title' => 'Начало работы',
                'slug' => 'getting-started',
                'description' => 'Авторизация, приглашения, рабочее пространство и первые действия.',
                'icon' => 'rocket',
                'color' => 'blue',
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'title' => 'Заявки и работы',
                'slug' => 'site-requests',
                'description' => 'Создание, согласование и исполнение заявок на объекте.',
                'icon' => 'clipboard-list',
                'color' => 'green',
                'sort_order' => 20,
                'is_active' => true,
            ],
            [
                'title' => 'Мобильное приложение',
                'slug' => 'mobile-app',
                'description' => 'Сценарии для прорабов и исполнителей в мобильном контуре.',
                'icon' => 'device-phone-mobile',
                'color' => 'orange',
                'sort_order' => 30,
                'is_active' => true,
            ],
            [
                'title' => 'Права и доступ',
                'slug' => 'access-control',
                'description' => 'Роли, права, приглашения и безопасная работа с доступами.',
                'icon' => 'shield-check',
                'color' => 'purple',
                'sort_order' => 40,
                'is_active' => true,
            ],
            [
                'title' => 'Склад и закупки',
                'slug' => 'warehouse-procurement',
                'description' => 'Материалы, складские операции, заявки на закупку и приемка.',
                'icon' => 'archive-box',
                'color' => 'slate',
                'sort_order' => 50,
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articles(): array
    {
        return [
            [
                'category_slug' => 'getting-started',
                'kind' => KnowledgeArticleKind::GUIDE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '1. Авторизация и вход в систему',
                'slug' => 'auth-and-login',
                'excerpt' => 'Как войти в личный кабинет, админку и мобильное приложение.',
                'content' => '<h2>Вход</h2><p>Используйте рабочую почту или телефон, которые указаны в приглашении организации.</p><h2>Выбор организации</h2><p>Если пользователь состоит в нескольких организациях, после входа выберите нужный рабочий контур.</p>',
                'tags' => ['авторизация', 'вход', 'организация'],
                'audiences' => ['all'],
                'surfaces' => ['lk', 'admin', 'mobile'],
                'context_keys' => ['auth.login', 'dashboard.login'],
                'reading_time' => 3,
                'is_featured' => true,
                'is_pinned' => true,
                'help_priority' => 10,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'parent_slug' => 'auth-and-login',
                'category_slug' => 'getting-started',
                'kind' => KnowledgeArticleKind::ARTICLE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '1.1. Восстановление пароля',
                'slug' => 'password-recovery',
                'excerpt' => 'Что делать, если пользователь забыл пароль или не получил письмо.',
                'content' => '<h2>Запрос ссылки</h2><p>Откройте форму восстановления пароля и укажите почту, привязанную к рабочему профилю.</p><h2>Если письмо не пришло</h2><p>Проверьте папку со спамом и попросите администратора организации сверить адрес в карточке пользователя.</p>',
                'tags' => ['пароль', 'доступ'],
                'audiences' => ['all'],
                'surfaces' => ['lk', 'admin', 'mobile'],
                'context_keys' => ['auth.forgot_password'],
                'reading_time' => 2,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'parent_slug' => 'auth-and-login',
                'category_slug' => 'access-control',
                'kind' => KnowledgeArticleKind::GUIDE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '1.2. Приглашение сотрудника',
                'slug' => 'invite-organization-user',
                'excerpt' => 'Как пригласить сотрудника и назначить ему роль.',
                'content' => '<h2>Приглашение</h2><p>Откройте раздел пользователей, укажите контактные данные сотрудника и выберите роль.</p><h2>Проверка прав</h2><p>Перед отправкой убедитесь, что роль содержит только необходимые разделы и действия.</p>',
                'tags' => ['пользователи', 'роли', 'права'],
                'audiences' => ['owner', 'admin'],
                'surfaces' => ['lk', 'admin'],
                'module_slugs' => ['users'],
                'permission_keys' => ['users.manage', 'admin.users.manage'],
                'context_keys' => ['users.invite', 'organization.users'],
                'reading_time' => 4,
                'sort_order' => 20,
                'published_at' => now(),
            ],
            [
                'category_slug' => 'site-requests',
                'kind' => KnowledgeArticleKind::GUIDE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '2. Заявки на объекте',
                'slug' => 'site-requests-overview',
                'excerpt' => 'Общий порядок работы с заявками от создания до закрытия.',
                'content' => '<h2>Жизненный цикл</h2><p>Заявка фиксирует потребность на объекте, проходит согласование, назначение ответственного и закрывается после выполнения.</p><h2>Контроль</h2><p>Следите за статусом, сроками, ответственными и связанными материалами.</p>',
                'tags' => ['заявки', 'объект', 'исполнение'],
                'audiences' => ['admin', 'manager', 'foreman'],
                'surfaces' => ['admin', 'mobile'],
                'module_slugs' => ['site-requests'],
                'permission_keys' => ['site_requests.view'],
                'context_keys' => ['site_requests.index', 'site_requests.detail'],
                'reading_time' => 4,
                'is_featured' => true,
                'is_pinned' => true,
                'help_priority' => 20,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'parent_slug' => 'site-requests-overview',
                'category_slug' => 'site-requests',
                'kind' => KnowledgeArticleKind::ARTICLE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '2.1. Создать заявку',
                'slug' => 'create-site-request',
                'excerpt' => 'Какие поля заполнить при создании заявки.',
                'content' => '<h2>Основные поля</h2><p>Укажите объект, тип заявки, описание потребности, срок и при необходимости приложите файлы.</p><h2>Перед отправкой</h2><p>Проверьте, что выбран правильный проект и заявка содержит достаточно данных для согласования.</p>',
                'tags' => ['заявки', 'создание'],
                'audiences' => ['admin', 'manager', 'foreman'],
                'surfaces' => ['admin', 'mobile'],
                'module_slugs' => ['site-requests'],
                'permission_keys' => ['site_requests.create', 'site_requests.edit'],
                'context_keys' => ['site_requests.create'],
                'reading_time' => 3,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'parent_slug' => 'site-requests-overview',
                'category_slug' => 'site-requests',
                'kind' => KnowledgeArticleKind::ARTICLE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '2.2. Согласовать и назначить заявку',
                'slug' => 'approve-and-assign-site-request',
                'excerpt' => 'Как проверить заявку, сменить статус и назначить ответственного.',
                'content' => '<h2>Согласование</h2><p>Откройте карточку заявки, проверьте описание, сроки и вложения, затем выберите следующее действие по процессу.</p><h2>Назначение</h2><p>Назначайте исполнителя только после проверки доступности проекта и требуемых материалов.</p>',
                'tags' => ['заявки', 'согласование', 'назначение'],
                'audiences' => ['admin', 'manager'],
                'surfaces' => ['admin'],
                'module_slugs' => ['site-requests'],
                'permission_keys' => ['site_requests.change_status', 'site_requests.assign'],
                'context_keys' => ['site_requests.workflow', 'site_requests.assign'],
                'reading_time' => 4,
                'sort_order' => 20,
                'published_at' => now(),
            ],
            [
                'category_slug' => 'mobile-app',
                'kind' => KnowledgeArticleKind::GUIDE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '3. Работа прораба в мобильном приложении',
                'slug' => 'mobile-foreman-workflow',
                'excerpt' => 'Ежедневный сценарий прораба: задачи, заявки, материалы и фотофиксация.',
                'content' => '<h2>Начало смены</h2><p>Откройте мобильное приложение, выберите проект и проверьте список действий на сегодня.</p><h2>Работа на объекте</h2><p>Создавайте заявки, прикладывайте фото, отмечайте фактическое выполнение и контролируйте материалы.</p>',
                'tags' => ['мобилка', 'прораб', 'объект'],
                'audiences' => ['foreman'],
                'surfaces' => ['mobile'],
                'module_slugs' => ['site-requests', 'warehouse', 'schedule'],
                'permission_keys' => ['site_requests.view'],
                'context_keys' => ['mobile.home', 'mobile.site_requests', 'mobile.warehouse'],
                'reading_time' => 5,
                'is_featured' => true,
                'is_pinned' => true,
                'help_priority' => 10,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'category_slug' => 'warehouse-procurement',
                'kind' => KnowledgeArticleKind::BEST_PRACTICE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '4. Материалы: от заявки до приемки',
                'slug' => 'materials-from-request-to-receipt',
                'excerpt' => 'Как связать потребность, закупку, склад и выдачу материалов.',
                'content' => '<h2>Потребность</h2><p>Фиксируйте материал в заявке или складской операции, чтобы команда видела источник потребности.</p><h2>Приемка</h2><p>После поступления проверьте количество, документы и привязку к проекту.</p>',
                'tags' => ['материалы', 'склад', 'закупки'],
                'audiences' => ['admin', 'manager', 'foreman'],
                'surfaces' => ['admin', 'mobile'],
                'module_slugs' => ['warehouse', 'procurement'],
                'permission_keys' => ['warehouse.view', 'procurement.view'],
                'context_keys' => ['warehouse.index', 'procurement.index'],
                'reading_time' => 5,
                'sort_order' => 10,
                'published_at' => now(),
            ],
            [
                'category_slug' => 'getting-started',
                'kind' => KnowledgeArticleKind::GUIDE,
                'status' => KnowledgeArticleStatus::PUBLISHED,
                'title' => '5. Управление модулями и пакетами',
                'slug' => 'modules-and-packages-management',
                'excerpt' => 'Как понять, какие возможности подключены организации и что можно изменить.',
                'content' => '<h2>Пакеты</h2><p>Пакет объединяет набор модулей под типовой сценарий работы организации.</p><h2>Отдельные модули</h2><p>Отдельный модуль стоит подключать, когда он нужен вне выбранного пакета или расширяет текущий процесс.</p><h2>Проверка перед изменением</h2><p>Перед отключением проверьте, какие сотрудники и разделы используют модуль.</p>',
                'tags' => ['модули', 'пакеты', 'подписка'],
                'audiences' => ['owner', 'admin'],
                'surfaces' => ['lk'],
                'module_slugs' => ['modules'],
                'context_keys' => ['modules.overview'],
                'reading_time' => 4,
                'is_featured' => true,
                'help_priority' => 30,
                'sort_order' => 30,
                'published_at' => now(),
            ],
        ];
    }
}
