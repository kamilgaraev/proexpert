<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\OrganizationCapability;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OneTimeDemoAccountsSeeder extends Seeder
{
    private const PASSWORD = 'ProhelperDemo123!';

    public function run(): void
    {
        $accounts = DB::transaction(function (): array {
            return array_map(
                fn (array $account): array => $this->upsertAccount($account),
                $this->accounts()
            );
        });

        $this->command?->newLine();
        $this->command?->info('Созданы или обновлены демо-аккаунты:');
        $this->command?->table(
            ['Контур', 'Email', 'Пароль', 'Организация', 'Роль'],
            $accounts
        );
    }

    /**
     * @param array<string, mixed> $account
     * @return array<int, string>
     */
    private function upsertAccount(array $account): array
    {
        $organization = Organization::query()->updateOrCreate(
            ['tax_number' => $account['tax_number']],
            [
                'name' => $account['organization_name'],
                'legal_name' => $account['legal_name'],
                'registration_number' => $account['registration_number'],
                'phone' => $account['phone'],
                'email' => $account['organization_email'],
                'address' => $account['address'],
                'city' => 'Москва',
                'postal_code' => '101000',
                'country' => 'RU',
                'is_active' => true,
                'is_verified' => true,
                'verified_at' => now(),
                'verification_status' => 'verified',
                'verification_data' => [
                    'source' => 'one_time_demo_accounts_seeder',
                    'verified_by' => 'seed',
                ],
                'organization_type' => 'single',
                'is_holding' => false,
                'hierarchy_level' => 0,
                'capabilities' => [$account['capability']],
                'primary_business_type' => $account['capability'],
                'specializations' => $account['specializations'],
                'certifications' => $account['certifications'],
                'profile_completeness' => 100,
                'onboarding_completed' => true,
                'onboarding_completed_at' => now(),
            ]
        );

        $user = User::query()->firstOrNew(['email' => $account['email']]);
        $user->forceFill([
            'name' => $account['name'],
            'email' => $account['email'],
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
            'phone' => $account['user_phone'],
            'position' => $account['position'],
            'is_active' => true,
            'current_organization_id' => $organization->id,
            'settings' => [
                'demo_account' => true,
                'contour' => $account['contour'],
            ],
            'has_completed_onboarding' => true,
        ])->save();

        $organization->users()->syncWithoutDetaching([
            $user->id => [
                'is_owner' => true,
                'is_active' => true,
                'settings' => json_encode([
                    'demo_account' => true,
                    'contour' => $account['contour'],
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);

        UserRoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_slug' => 'organization_owner',
                'context_id' => $context->id,
            ],
            [
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                'assigned_by' => null,
                'expires_at' => null,
                'is_active' => true,
            ]
        );

        return [
            $account['contour'],
            $account['email'],
            self::PASSWORD,
            $organization->name,
            'organization_owner',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accounts(): array
    {
        return [
            [
                'contour' => 'Генподрядчик',
                'email' => 'demo.general-contractor@prohelper.test',
                'name' => 'Демо Генподрядчик',
                'position' => 'Руководитель генподряда',
                'role_slug' => 'organization_owner',
                'organization_name' => 'Демо Генподряд',
                'legal_name' => 'ООО Демо Генподряд',
                'tax_number' => '7701000001',
                'registration_number' => '1027701000001',
                'organization_email' => 'org.general-contractor@prohelper.test',
                'phone' => '+7 495 100-00-01',
                'user_phone' => '+7 916 100-00-01',
                'address' => 'Москва, ул. Строителей, 1',
                'capability' => OrganizationCapability::GENERAL_CONTRACTING->value,
                'specializations' => ['Генеральный подряд', 'Управление строительством'],
                'certifications' => ['СРО строительство'],
            ],
            [
                'contour' => 'Подрядчик',
                'email' => 'demo.contractor@prohelper.test',
                'name' => 'Демо Подрядчик',
                'position' => 'Директор подрядной организации',
                'organization_name' => 'Демо Подряд',
                'legal_name' => 'ООО Демо Подряд',
                'tax_number' => '7701000002',
                'registration_number' => '1027701000002',
                'organization_email' => 'org.contractor@prohelper.test',
                'phone' => '+7 495 100-00-02',
                'user_phone' => '+7 916 100-00-02',
                'address' => 'Москва, ул. Монтажная, 2',
                'capability' => OrganizationCapability::SUBCONTRACTING->value,
                'specializations' => ['Монолитные работы', 'Отделочные работы'],
                'certifications' => ['СРО строительство'],
            ],
            [
                'contour' => 'Поставщик',
                'email' => 'demo.supplier@prohelper.test',
                'name' => 'Демо Поставщик',
                'position' => 'Менеджер снабжения',
                'organization_name' => 'Демо Поставка',
                'legal_name' => 'ООО Демо Поставка',
                'tax_number' => '7701000003',
                'registration_number' => '1027701000003',
                'organization_email' => 'org.supplier@prohelper.test',
                'phone' => '+7 495 100-00-03',
                'user_phone' => '+7 916 100-00-03',
                'address' => 'Москва, Складская ул., 3',
                'capability' => OrganizationCapability::MATERIALS_SUPPLY->value,
                'specializations' => ['Поставка материалов', 'Складская логистика'],
                'certifications' => ['Дилерские сертификаты'],
            ],
            [
                'contour' => 'Заказчик',
                'email' => 'demo.customer@prohelper.test',
                'name' => 'Демо Заказчик',
                'position' => 'Представитель заказчика',
                'organization_name' => 'Демо Заказчик',
                'legal_name' => 'ООО Демо Заказчик',
                'tax_number' => '7701000004',
                'registration_number' => '1027701000004',
                'organization_email' => 'org.customer@prohelper.test',
                'phone' => '+7 495 100-00-04',
                'user_phone' => '+7 916 100-00-04',
                'address' => 'Москва, Инвесторская ул., 4',
                'capability' => OrganizationCapability::CONSULTING->value,
                'specializations' => ['Управление инвестиционными проектами'],
                'certifications' => ['Проектное управление'],
            ],
            [
                'contour' => 'Проектировщик',
                'email' => 'demo.designer@prohelper.test',
                'name' => 'Демо Проектировщик',
                'position' => 'Главный инженер проекта',
                'organization_name' => 'Демо Проектирование',
                'legal_name' => 'ООО Демо Проектирование',
                'tax_number' => '7701000005',
                'registration_number' => '1027701000005',
                'organization_email' => 'org.designer@prohelper.test',
                'phone' => '+7 495 100-00-05',
                'user_phone' => '+7 916 100-00-05',
                'address' => 'Москва, Проектная ул., 5',
                'capability' => OrganizationCapability::DESIGN->value,
                'specializations' => ['Проектная документация', 'Авторский надзор'],
                'certifications' => ['СРО проектирование'],
            ],
            [
                'contour' => 'Стройконтроль',
                'email' => 'demo.supervision@prohelper.test',
                'name' => 'Демо Стройконтроль',
                'position' => 'Инженер строительного контроля',
                'organization_name' => 'Демо Стройконтроль',
                'legal_name' => 'ООО Демо Стройконтроль',
                'tax_number' => '7701000006',
                'registration_number' => '1027701000006',
                'organization_email' => 'org.supervision@prohelper.test',
                'phone' => '+7 495 100-00-06',
                'user_phone' => '+7 916 100-00-06',
                'address' => 'Москва, Контрольная ул., 6',
                'capability' => OrganizationCapability::CONSTRUCTION_SUPERVISION->value,
                'specializations' => ['Технический надзор', 'Приемка работ'],
                'certifications' => ['Аттестация стройконтроля'],
            ],
            [
                'contour' => 'Аренда техники',
                'email' => 'demo.equipment@prohelper.test',
                'name' => 'Демо Аренда Техники',
                'position' => 'Менеджер парка техники',
                'organization_name' => 'Демо Техника',
                'legal_name' => 'ООО Демо Техника',
                'tax_number' => '7701000007',
                'registration_number' => '1027701000007',
                'organization_email' => 'org.equipment@prohelper.test',
                'phone' => '+7 495 100-00-07',
                'user_phone' => '+7 916 100-00-07',
                'address' => 'Москва, Транспортная ул., 7',
                'capability' => OrganizationCapability::EQUIPMENT_RENTAL->value,
                'specializations' => ['Аренда спецтехники', 'Механизация работ'],
                'certifications' => ['Паспорта техники'],
            ],
            [
                'contour' => 'Эксплуатация',
                'email' => 'demo.facility@prohelper.test',
                'name' => 'Демо Эксплуатация',
                'position' => 'Руководитель эксплуатации',
                'organization_name' => 'Демо Эксплуатация',
                'legal_name' => 'ООО Демо Эксплуатация',
                'tax_number' => '7701000008',
                'registration_number' => '1027701000008',
                'organization_email' => 'org.facility@prohelper.test',
                'phone' => '+7 495 100-00-08',
                'user_phone' => '+7 916 100-00-08',
                'address' => 'Москва, Эксплуатационная ул., 8',
                'capability' => OrganizationCapability::FACILITY_MANAGEMENT->value,
                'specializations' => ['Эксплуатация объектов', 'Сервисное обслуживание'],
                'certifications' => ['Эксплуатационные регламенты'],
            ],
            [
                'contour' => 'Прораб',
                'email' => 'demo.foreman@prohelper.test',
                'name' => 'Демо Прораб',
                'position' => 'Прораб участка',
                'organization_name' => 'Демо Участок',
                'legal_name' => 'ООО Демо Участок',
                'tax_number' => '7701000009',
                'registration_number' => '1027701000009',
                'organization_email' => 'org.foreman@prohelper.test',
                'phone' => '+7 495 100-00-09',
                'user_phone' => '+7 916 100-00-09',
                'address' => 'Москва, Объектовая ул., 9',
                'capability' => OrganizationCapability::SUBCONTRACTING->value,
                'specializations' => ['Полевое управление', 'Исполнительная документация'],
                'certifications' => ['Охрана труда'],
            ],
            [
                'contour' => 'Наблюдатель',
                'email' => 'demo.observer@prohelper.test',
                'name' => 'Демо Наблюдатель',
                'position' => 'Наблюдатель проекта',
                'organization_name' => 'Демо Наблюдение',
                'legal_name' => 'ООО Демо Наблюдение',
                'tax_number' => '7701000010',
                'registration_number' => '1027701000010',
                'organization_email' => 'org.observer@prohelper.test',
                'phone' => '+7 495 100-00-10',
                'user_phone' => '+7 916 100-00-10',
                'address' => 'Москва, Обзорная ул., 10',
                'capability' => OrganizationCapability::CONSULTING->value,
                'specializations' => ['Мониторинг проектов', 'Отчетность'],
                'certifications' => ['Внутренний аудит'],
            ],
        ];
    }
}
