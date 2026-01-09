<?php

return [
    'legacy_event_sourcing_unavailable' => 'Данный договор не использует систему событий',
    'event_sourcing_activation_hint' => 'Для активации системы событий добавьте дополнительное соглашение или спецификацию к договору',
    
    'work_type_category' => [
        'smr' => 'Строительно-монтажные работы',
        'general_construction' => 'Общестроительные работы',
        'finishing' => 'Отделочные работы',
        'installation' => 'Монтажные работы',
        'design' => 'Проектирование',
        'consultation' => 'Консультационные услуги',
        'supply' => 'Поставка',
        'services' => 'Услуги',
        'equipment' => 'Техника',
        'materials' => 'ТМЦ',
        'rent' => 'Аренда',
        'other' => 'Прочие',
    ],

    // Messages
    'organization_context_missing' => 'Не определён контекст организации',
    'allocation_updated' => 'Распределение контракта успешно обновлено',
    'allocation_update_error' => 'Ошибка при обновлении распределения',
    'auto_allocation_multi_project_only' => 'Автоматическое распределение доступно только для мультипроектных контрактов',
    'auto_equal_allocation_created' => 'Создано равномерное распределение',
    'allocation_create_error' => 'Ошибка при создании распределения',
    'acts_allocation_multi_project_only' => 'Распределение на основе актов доступно только для мультипроектных контрактов',
    'acts_allocation_created' => 'Создано распределение на основе актов',
    'allocation_converted_to_fixed' => 'Распределение конвертировано в фиксированное',
    'allocation_convert_error' => 'Ошибка при конвертации',
    'allocation_deleted' => 'Распределение успешно удалено',
    'allocation_delete_error' => 'Ошибка при удалении',
    'auto_allocations_recalculated' => 'Автоматические распределения пересчитаны',
    'recalculation_error' => 'Ошибка при пересчете',

    // Contract Controller Messages
    'contract_id_missing' => 'ID контракта не указан',
    'contract_not_found' => 'Контракт не найден',
    'create_error' => 'Не удалось создать контракт',
    'invalid_data' => 'Некорректные данные',
    'update_error' => 'Не удалось обновить контракт',
    'deleted' => 'Контракт успешно удален',
    'delete_error' => 'Не удалось удалить контракт',
    'attached_to_parent' => 'Контракт успешно привязан к родительскому контракту',
    'attach_error' => 'Ошибка при привязке контракта',
    'detached_from_parent' => 'Контракт успешно отвязан от родительского контракта',
    'detach_error' => 'Ошибка при отвязке контракта',

    // Contract State Event Controller
    'timeline_error' => 'Ошибка при получении истории событий',
    'state_error' => 'Ошибка при получении состояния',
    'legacy_unavailable' => 'Договор не использует Event Sourcing (legacy)',
    'contract_mismatch' => 'Контракт не принадлежит текущему проекту',

    // Contract Performance Act Controller
    'act_not_found' => 'Акт выполненных работ не найден',
    'act_created' => 'Акт выполненных работ успешно создан',
    'act_updated' => 'Акт выполненных работ успешно обновлен',
    'act_deleted' => 'Акт выполненных работ успешно удален',
    'act_export_error' => 'Ошибка при экспорте акта',
    'act_files_error' => 'Ошибка при получении файлов акта',
    'act_retrieve_error' => 'Не удалось получить акты выполненных работ',
    'act_create_error' => 'Не удалось создать акт выполненных работ',
    'act_update_error' => 'Не удалось обновить акт выполненных работ',
    'act_delete_error' => 'Не удалось удалить акт выполненных работ',
    'available_works_error' => 'Не удалось получить доступные работы',

    // Estimate Contract Controller
    'estimate_created' => 'Смета успешно создана из договора',
    'estimate_linked' => 'Смета успешно привязана к договору',
    'estimate_unlinked' => 'Смета успешно отвязана от договора',
    'estimate_error' => 'Ошибка при работе со сметой',

    // Contract Payment Controller
    'payment_not_found' => 'Платеж не найден или нет доступа',
    'payment_created' => 'Платеж успешно создан',
    'payment_updated' => 'Платеж успешно обновлен',
    'payment_deleted' => 'Платеж успешно удален',
    'payment_retrieve_error' => 'Не удалось получить платежи',
    'payment_create_error' => 'Не удалось создать платеж',
    'payment_update_error' => 'Не удалось обновить платеж',
    'payment_delete_error' => 'Не удалось удалить платеж',

    // Contract Specification Controller
    'specification_not_found' => 'Спецификация не найдена или нет доступа',
    'specification_created' => 'Спецификация успешно создана и привязана к контракту',
    'specification_attached' => 'Спецификация успешно привязана к контракту',
    'specification_detached' => 'Спецификация успешно отвязана от контракта',
    'specification_error' => 'Ошибка при работе со спецификацией',
    'specification_already_attached' => 'Спецификация уже привязана к контракту',
    'specification_retrieve_error' => 'Ошибка при получении спецификаций',
    'specification_create_error' => 'Ошибка при создании спецификации',
    'specification_attach_error' => 'Ошибка при привязке спецификации',
    'specification_detach_error' => 'Ошибка при отвязке спецификации',

    // Contractor Verification
    'contractor_already_confirmed' => 'Подрядчик уже подтвержден',
    'verification_expired' => 'Срок подтверждения истек',
    'verification_confirmed' => 'Подрядчик успешно подтвержден. Ограничения доступа сняты.',
    'verification_error' => 'Ошибка при подтверждении подрядчика',
    'contractor_already_rejected' => 'Подрядчик уже отклонен',
    'verification_rejected' => 'Доступ заблокирован. Мы начали расследование.',
    'rejection_error' => 'Ошибка при отклонении подрядчика',
    'dispute_created' => 'Жалоба принята. Мы проверим ситуацию.',
    'dispute_error' => 'Ошибка при создании жалобы',

    // Contractor Reports
    'report_error' => 'Ошибка при формировании отчета',

    // Contractor Invitations
    'invitations_retrieve_error' => 'Ошибка при получении приглашений',
    'invitation_expired' => 'Срок действия приглашения истек',
    'invitation_not_found' => 'Приглашение не найдено',
    'invitation_retrieve_error' => 'Ошибка при получении приглашения',
    'invitation_accepted' => 'Приглашение принято. Теперь вы можете работать с данной организацией как подрядчик.',
    'invitation_accept_error' => 'Ошибка при принятии приглашения',
    'invitation_declined' => 'Приглашение отклонено',
    'invitation_decline_error' => 'Ошибка при отклонении приглашения',
    'invitation_stats_error' => 'Ошибка при получении статистики',
    'invitation_sent' => 'Приглашение успешно отправлено',
    'invitation_create_error' => 'Ошибка при создании приглашения',
    'invitation_cancelled' => 'Приглашение отменено',
    'invitation_cancel_error' => 'Ошибка при отмене приглашения',

    // Contractor Controller
    'contractor_create_error' => 'Не удалось создать подрядчика',
    'contractor_not_found' => 'Подрядчик не найден',
    'contractor_update_error' => 'Не удалось обновить подрядчика',
    'contractor_deleted' => 'Подрядчик успешно удален',
    'contractor_delete_error' => 'Не удалось удалить подрядчика',
];
