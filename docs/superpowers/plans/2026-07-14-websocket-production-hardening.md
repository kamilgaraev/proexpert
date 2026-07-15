# WebSocket production hardening plan

**Goal:** восстановить realtime-уведомления МОСТ и сделать доставку, авторизацию и production-развёртывание отказоустойчивыми и проверяемыми.

**Architecture:** единственный поддерживаемый realtime-контракт — приватный канал пользователя `App.Models.User.{id}.{interface}` для `admin` и `lk`. Backend публикует через штатный Laravel broadcaster; ошибка Reverb считается ошибкой доставки и приводит к повтору задания без повторной отправки уже успешных каналов. Неиспользуемые организационные и платёжные WebSocket-контракты удаляются.

**Stack:** Laravel 11, Laravel Reverb 1.7, Horizon, React/Vite, Laravel Echo, Vitest, PHPUnit/Larastan.

## Task 1: Защитить frontend-конфигурацию Reverb

- Добавить в admin и LK тестируемую проверку production-ключа: пустой и известный placeholder запрещены.
- Перевести deploy workflow на GitHub secret `REVERB_APP_KEY` и запускать проверку до сборки.
- Исправить LK listener на `.notification.new`.
- Удалить неиспользуемый admin-сервис подписки на несуществующий канал `payments`.
- Запустить целевые Vitest-тесты и TypeScript checks без production build.

## Task 2: Сделать backend-доставку подтверждаемой и повторяемой

- Написать unit-тесты для публикации через `Illuminate\Contracts\Broadcasting\Factory`, корректного payload и проброса ошибки.
- Заменить ручную подпись/HTTP-вызов Reverb на штатный broadcaster.
- Написать тесты задания: успешные каналы на повторе пропускаются, любой сбой приводит к исключению после обработки остальных каналов.
- Изменить `NotificationService::sendViaChannel`, чтобы `false` и исключения отмечались как failure и не скрывались.

## Task 3: Ужесточить авторизацию и убрать мёртвые broadcast-контракты

- Добавить проверки разделения admin/LK в channel authorization.
- Удалить общий fallback `/broadcasting/auth`.
- Удалить неиспользуемый `NotificationBroadcast`.
- Превратить события контрактов в обычные доменные события: realtime уже отправляет `Notify`; открыть методы данных предупреждения, которые вызывает интеграция.

## Task 4: Укрепить production topology

- Ограничить origins Reverb доменами МОСТ и задать конечный лимит соединений.
- Привязать порт Reverb к loopback и добавить healthcheck контейнера.
- Назначить очередь `broadcast` Horizon и добавить wait threshold.
- В deploy workflow остановить legacy Supervisor workers до запуска контейнерного Horizon и проверить здоровье Reverb после развёртывания.
- Покрыть compose, Horizon и workflow статическими production-contract тестами.

## Task 5: Проверка, коммиты и слияние

- Запустить backend PHPUnit/Larastan на затронутых файлах, frontend Vitest/TypeScript/lint на затронутом коде.
- Проверить diff и отсутствие placeholder/секретов.
- Сделать отдельные русские Conventional Commits в трёх ветках.
- Слить каждую ветку в локальный `main` соответствующего репозитория без push и повторно проверить merge-состояние.
