# Аудит авторизации и регистрации МОСТ

**Дата аудита:** 24.08.2026

**Статус:** диагностическая спецификация, без реализации

**Общий риск:** High

**Контур:** регистрация, организации и членства, подтверждение контакта, вход, access/refresh/revoke/logout, восстановление пароля, приглашения, повторная регистрация, CORS и production-сетевой тракт

## 1. Назначение документа

Документ фиксирует результаты независимого сквозного аудита auth-контура МОСТ и объединяет:

- статический анализ пяти технических проектов;
- исторические Safari DevTools screenshots инцидента 21.08.2026;
- повторные безопасные HTTP `HEAD`/`GET`/`OPTIONS` проверки production 24.08.2026;
- read-only анализ nginx access/error logs и фактически развёрнутой конфигурации через `codex-ro`;
- проверку доступных событий GlitchTip;
- рекомендации по исправлению и обязательной регрессии.

Документ не является утверждением, что рекомендации уже реализованы. Любая реализация требует отдельной задачи, ветки, тестов, code review и post-deploy проверки.

## 2. Executive summary

Auth-контур МОСТ в целом содержит зрелые механизмы для основного web-входа: короткоживущий access token в памяти, защищённый refresh cookie, серверные auth-сессии, rotation/revoke, подписанное подтверждение email и атомарный password reset. Однако полный контур неоднороден между landing, admin, customer и mobile.

Выявлено 18 замечаний:

| Severity | Количество | Основной риск |
|---|---:|---|
| Critical | 0 | — |
| High | 9 | недоступность регистрации/кабинета, неполные учётные записи, replay приглашений, продолжение доступа после удаления членства, утечка auth-данных в логи |
| Medium | 8 | request storm, технические сетевые ошибки, multi-tab refresh race, отсутствие серверного consent evidence, доступность и неодинаковые контракты |
| Low | 1 | некорректная диагностика CORS-отказа |

Главный production-вывод по инциденту 21.08.2026: дефект подтверждён. За семь секунд nginx зафиксировал 26 одинаковых preflight-запросов DaData, все завершились HTTP 403, после чего два `POST /landing/auth/register` также получили 403. Ответ сформирован Laravel `CorsMiddleware`, а не nginx, WAF или внешним proxy. Нынешний redirect `/register` с корневого домена на `lk` снижает вероятность повторения основного сценария, но несовместимость root origin с landing-аудиторией API сохраняется. Отдельно подтверждён актуальный CORS-дефект customer-портала: production frontend обращается к API напрямую, а customer origins backend не разрешает.

## 3. Область и источники

### 3.1. Технические проекты

| Проект | Назначение в аудите |
|---|---|
| `prohelper` | Laravel API, middleware, JWT, auth-сессии, регистрации, приглашения, роли, tenant context |
| `prohelper_land` | основной сайт, регистрация, личный кабинет, DaData, web auth client |
| `prohelper_admin` | общий web auth-контур административного интерфейса |
| `prohelper_customers` | customer registration/login/invitations и хранение токена |
| `prohelpers_mobile` | legacy JWT login/refresh/logout и безопасное локальное хранение |

Корневой каталог `prohelper_full` не является git-репозиторием. Состояния всех пяти репозиториев проверены отдельно. Во время аудита не изменялись существующие пользовательские ветки и не удалялись артефакты `.codebase-memory/`.

### 3.2. Production evidence

- Runtime deploy backend на момент SSH-проверки: `87495895dfe7622bc33d34b7b39ebb04c329039f`.
- SHA-256 production и локальных файлов совпали:
  - `app/Http/Middleware/CorsMiddleware.php`: `e2f3068d472233f1edf52c97f2236ac02dc0fb1323d18028dc2370d92532b6fb`;
  - `config/web_auth.php`: `d40a0d1214a7c16f42bac37ed26281c289e3d1736a42ed111ec196ca490589ba`.
- nginx site `/etc/nginx/sites-enabled/prohelper-api` не содержит CORS-правил и проксирует API в Laravel.
- Исторический access log: `/var/log/nginx/prohelper_access.log.3.gz`, интервал 21.08.2026 19:57:39–19:57:53 MSK.
- GlitchTip: релевантных exception issues с `lastSeen >= 2026-08-20` не обнаружено. Это не опровергает инцидент: управляемый CORS 403 не создаёт exception.
- Screenshots:
  - `C:\Users\KAMILG~1\AppData\Local\Temp\codex-clipboard-7bbc836d-3765-4a7d-8155-9c3ecf252f46.png`;
  - `C:\Users\KAMILG~1\AppData\Local\Temp\codex-clipboard-35e44b6a-38bf-4481-b3b4-e54c6a8dbf65.png`.

Личные IP-адреса, токены, cookie, секреты и содержимое `.env` в документ не включены.

## 4. Инварианты auth-контура

1. Регистрация создаёт пользователя, организацию, активное членство и начальную роль атомарно либо не создаёт ничего.
2. Повтор запроса после timeout не создаёт дубли и возвращает семантически тот же результат.
3. Недоступность DaData не блокирует ручное заполнение и регистрацию.
4. CORS разрешает только фактические доверенные origins, одинаково работает на success и error responses и не заменяет auth/authorization.
5. Удалённое или неактивное членство немедленно прекращает tenant-доступ, включая refresh.
6. Refresh token вращается, replay обнаруживается, logout фактически инвалидирует сессию.
7. Verification, reset и invitation ограничены сроком, субъектом и single-use/idempotent переходом.
8. Ошибки не раскрывают наличие аккаунта, внутренние exception messages, токены и персональные данные.
9. UI предотвращает двойной submit и request storm, корректно обрабатывает offline, timeout, conflict и медленную сеть.
10. Роли и organization/project context проверяются сервером на каждом защищённом переходе.

## 5. Карта auth-flow

| Переход | Реализация | Статус | Ключевое замечание |
|---|---|---|---|
| Main registration → user/org/membership/owner role | `JwtAuthService::register` | Частично корректно | основная транзакция атомарна, но нет idempotency и восстановимого post-commit процесса |
| Customer registration → user/org/membership/customer_owner | `CustomerAuthService::register` | Дефект | ошибка назначения owner-роли проглатывается |
| Customer invitation registration | `CustomerAuthService::registerByInvitation` | Дефект | создание аккаунта и принятие приглашения разделены |
| Email verification | signed route + hash/id | Корректно с оговоркой | 60 минут; повтор после успеха идемпотентен |
| Main login | modern web auth | Корректно | непроверенный email не допускается |
| Admin login | modern web auth | Корректно | отдельная audience и origin policy |
| Customer/mobile login | legacy bearer JWT | Частично корректно | active membership не проверяется централизованно на каждом запросе |
| Access refresh | web auth session rotation | Частично корректно | server-side revoke есть, но возможна multi-tab race |
| Legacy refresh | JWT refresh/blacklist | Частично корректно | старый organization claim может пережить удаление membership |
| Logout/revoke | auth session revoke/blacklist | Корректно для штатного запроса | web-сессия инвалидируется фактически |
| Forgot/reset password | anti-enumeration + transactional reset | Корректно | token 60 минут, row locks, session revoke |
| Invitation accept/decline | invitation token/status | Частично корректно | отсутствует row lock при конкурентном accept; токены логируются |
| Repeat registration | unique email validation/DB constraint | Частично корректно | дубль не создаётся, но retry после потерянного ответа превращается в конфликт |

## 6. Вердикт по CORS-инциденту

### 6.1. Подтверждённый runtime trace 21.08.2026

| Время MSK | Запрос | Количество | Результат |
|---|---|---:|---|
| 19:57:39–19:57:46 | `OPTIONS /api/v1/landing/dadata/suggest/organizations` | 26 | HTTP 403 |
| 19:57:49–19:57:53 | `POST /api/v1/landing/auth/register` | 2 | HTTP 403 |

Все запросы имели referer корневого punycode origin и Safari/macOS user agent. Это подтверждает одновременно несовместимость origin policy и request storm на поле организации.

### 6.2. Текущее состояние 24.08.2026

- `https://xn--1-xtbgmf.xn--p1ai/register` отвечает 301 и направляет на `https://lk.xn--1-xtbgmf.xn--p1ai/register`.
- Preflight с root origin к landing register и DaData по-прежнему получает 403 без `Access-Control-Allow-Origin`.
- Те же endpoints с `lk` origin получают 204, точный `Access-Control-Allow-Origin`, `Access-Control-Allow-Credentials: true`, `Vary: Origin`, `Access-Control-Max-Age: 86400`.
- Production nginx не формирует CORS headers; хеш production middleware совпадает с репозиторием.
- Следовательно, первопричина находится в app-layer audience mapping Laravel. Внешний proxy/WAF не является источником 403.
- Текущий redirect является эксплуатационным смягчением, а не устранением несогласованности контракта.
- Customer origin остаётся полностью запрещённым, хотя production customer bundle обращается к API напрямую.

**Вердикт:** исторический дефект подтверждён; основной путь сейчас смягчён redirect-ом, но дефект контракта не устранён. Customer-вариант актуален сейчас. Периодичность возможна при появлении root-origin UI, старого bundle, внешней ссылки без redirect либо отличающегося маршрута.

## 7. Матрица origin × endpoint

Обозначения: `O` — точный `Access-Control-Allow-Origin`; `C` — `Access-Control-Allow-Credentials: true`; `204` — успешный preflight; `403` — запрещённый origin.

| Origin | Landing register | DaData suggest | Admin login | Customer login |
|---|---:|---:|---:|---:|
| `https://xn--1-xtbgmf.xn--p1ai` | 403 | 403 | 403 | 204 + O, без C |
| `https://www.xn--1-xtbgmf.xn--p1ai` | 403 | 403 | 403 | 204 + O, без C |
| `https://lk.xn--1-xtbgmf.xn--p1ai` | 204 + O + C | 204 + O + C | 403 | 403 |
| `https://admin.xn--1-xtbgmf.xn--p1ai` | 403 | 403 | 204 + O + C | 403 |
| `https://customer.xn--1-xtbgmf.xn--p1ai` | 403 | 403 | 403 | 403 |
| `https://prohelper.pro` / `https://www.prohelper.pro` | 403 | 403 | 403 | 204 + O, без C |
| `https://lk.prohelper.pro` | 204 + O + C | 204 + O + C | 403 | 403 |
| `https://admin.prohelper.pro` | 403 | 403 | 204 + O + C | 403 |
| `https://customer.prohelper.pro` | 403 | 403 | 403 | 403 |

Для разрешённых origins подтверждены `Vary: Origin`, explicit methods/headers и max-age 86400. Wildcard с credentials не используется. CORS headers сохраняются на проверенных 404/405 для разрешённого origin. Для запрещённого origin отсутствие ACAO ожидаемо, поэтому браузер скрывает тело 403.

## 8. Матрица токенов и сессий

| Клиент | Access | Refresh | Хранение | Rotation/revoke |
|---|---|---|---|---|
| Main web | 15 минут | 1 день, remember 14 дней | access в памяти; `__Host-` HttpOnly/Secure/SameSite=Strict cookie | server-side auth session, rotation, replay revoke |
| Admin web | 15 минут | 1 день, remember 14 дней | access в памяти; защищённый refresh cookie | отдельная audience, server-side revoke |
| Customer web | legacy JWT, 60 минут | 14 дней | `sessionStorage` | JWT blacklist, grace period 30 секунд |
| Mobile | legacy JWT, 60 минут | 14 дней | `flutter_secure_storage` | JWT blacklist, in-process single-flight refresh |

## 9. Матрица ролей и tenant-границ

| Контур | Начальная/доступная роль | Organization boundary | Project boundary | Статус |
|---|---|---|---|---|
| Main registration | `organization_owner` | membership + organization context | далее permission/project checks | атомарно создаётся |
| Customer registration | `customer_owner` | membership | customer project access | назначение роли может отсутствовать |
| Customer delegated roles | `customer_manager`, `approver`, `viewer`, `curator`, `financier`, `legal`, `observer` | organization-scoped | permissions проекта | требуется единая active-membership проверка |
| Project roles | `owner`, `customer`, `general_contractor`, `contractor`, `subcontractor`, `construction_supervision`, `designer`, `observer`, `parent_administrator` | organization context обязателен | project participant/permission | invitation race может нарушить единственность принятия |
| Mobile | роль из membership/JWT context | переключение проверяет membership | endpoint authorization | старый token context может пережить деактивацию membership |

## 10. Реестр замечаний

### AUTH-001 — customer origin отсутствует в CORS policy

- **Класс:** подтверждённый дефект.
- **Severity:** High.
- **Сценарий и инвариант:** customer portal → API; фактически размещённый доверенный origin должен проходить preflight только для customer audience.
- **Файлы/runtime:** `prohelper_customers/src/shared/config/env.ts:4`; `prohelper_customers/src/shared/api/customerApi.ts:49`; `prohelper/app/Http/Middleware/CorsMiddleware.php`; `prohelper/config/web_auth.php`.
- **Воспроизведение:** отправить `OPTIONS /api/v1/customer/auth/login` с `Origin: https://customer.xn--1-xtbgmf.xn--p1ai` и требуемыми headers.
- **Evidence:** production отвечает 403; production customer JS bundle содержит прямой API URL; root public origin при этом получает 204, что демонстрирует неверную audience mapping.
- **Эффект:** customer login и защищённые API-вызовы блокируются браузером; CORS-разрешение не совпадает с реальной доверенной поверхностью.
- **Направление исправления:** выделить `customer` audience и отдельный allowlist Unicode/punycode/служебных доменов; customer routes классифицировать только в неё; credentials определить по фактической модели токенов.
- **Регрессия:** table-driven middleware tests, browser smoke для login/refresh/logout и отрицательные cross-audience tests.

### AUTH-002 — root registration origin запрещён landing audience

- **Класс:** подтверждённый дефект.
- **Severity:** High.
- **Сценарий и инвариант:** main registration с production UI origin; UI и API contract должны использовать один разрешённый origin либо гарантированно перенаправлять до загрузки приложения.
- **Файлы/runtime:** `prohelper/config/web_auth.php`; `prohelper/app/Http/Middleware/CorsMiddleware.php`; `/var/log/nginx/prohelper_access.log.3.gz`, 21.08.2026 19:57:39–19:57:53 MSK.
- **Воспроизведение:** `OPTIONS` к landing register/DaData с root origin; текущий ответ 403. Исторически открыть registration bundle на root origin.
- **Evidence:** 26 preflight 403 и 2 register POST 403; Safari screenshots; root `/register` сейчас 301 на `lk`; production и local middleware/config hashes совпадают.
- **Эффект:** невозможность регистрации, непрозрачный browser network error, повторные отправки формы.
- **Направление исправления:** формально выбрать канонический registration origin; обеспечить redirect до SPA и удалить root-origin API-вызовы либо осознанно добавить root в landing audience. Закрепить контракт инфраструктурным тестом.
- **Регрессия:** post-deploy matrix root/lk, redirect smoke, preflight на success/validation/5xx, Safari/Chromium E2E.

### AUTH-003 — legacy JWT не инвалидирует неактивное membership централизованно

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** High.
- **Сценарий и инвариант:** удаление/деактивация членства → следующий customer/mobile запрос и refresh; tenant-доступ должен прекратиться немедленно.
- **Файлы:** `prohelper/app/Http/Middleware/SetOrganizationContext.php:107`; `prohelper/app/Services/Auth/JwtAuthService.php:373-382`; `prohelper/app/Http/Middleware/EnsureAuthSessionIsActive.php`; `prohelper/app/Models/User.php` (`organizations`).
- **Воспроизведение:** получить JWT с organization claim, деактивировать pivot административным штатным сценарием, повторить защищённый запрос/refresh тем же токеном.
- **Evidence:** organization lookup не фильтрует active pivot; refresh сохраняет organization claim; session middleware проверяет пользователя/сессию, но не active membership.
- **Эффект:** бывший участник может сохранять tenant-доступ до истечения/отзыва токена; риск cross-tenant disclosure.
- **Направление исправления:** единая server-side active-membership проверка для request и refresh, с отзывом auth session при потере membership.
- **Регрессия:** PostgreSQL feature tests для inactive/deleted pivot, refresh после удаления, switch organization и cross-tenant endpoints.

### AUTH-004 — customer registration проглатывает ошибку owner-роли

- **Класс:** подтверждённый дефект.
- **Severity:** High.
- **Сценарий и инвариант:** customer registration; user/org/membership/initial role создаются атомарно.
- **Файл:** `prohelper/app/Services/Customer/CustomerAuthService.php:129-145`.
- **Воспроизведение:** принудить repository назначения `customer_owner` вернуть ошибку при регистрации.
- **Evidence:** исключение назначения роли перехватывается внутри транзакции, после чего регистрация завершается успешно.
- **Эффект:** аккаунт существует без минимальных полномочий, UI получает 201, восстановление требует ручного вмешательства.
- **Направление исправления:** считать начальную роль обязательной частью транзакции; rollback на любой ошибке.
- **Регрессия:** fault-injection feature test, проверяющий отсутствие user/org/membership после ошибки роли.

### AUTH-005 — invitation registration неатомарна

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** High.
- **Сценарий и инвариант:** регистрация customer по приглашению; создание аккаунта и принятие invitation являются одним восстановимым workflow.
- **Файл:** `prohelper/app/Services/Customer/CustomerAuthService.php:354-403`.
- **Воспроизведение:** завершить transaction создания user/org, затем вызвать ошибку на acceptance.
- **Evidence:** acceptance выполняется после commit отдельным вызовом.
- **Эффект:** появляется полноценный аккаунт без ожидаемого project access; retry конфликтует с уже занятым email.
- **Направление исправления:** единая транзакционная orchestration либо явная idempotent saga с состояниями и безопасным resume.
- **Регрессия:** failure-after-commit, retry и compensation tests.

### AUTH-006 — конкурентное принятие invitation не сериализовано

- **Класс:** подтверждённый дефект.
- **Severity:** High.
- **Сценарий и инвариант:** два конкурентных accept одного token; приглашение должно перейти из pending ровно один раз и только к одной организации.
- **Файл:** `prohelper/app/Services/Projects/ProjectParticipantInvitationService.php:192`, `:339`.
- **Воспроизведение:** параллельно отправить два accept с разными допустимыми organization contexts.
- **Evidence:** invitation читается без `lockForUpdate`; transaction не перечитывает строку под блокировкой перед переходом статуса.
- **Эффект:** двойное прикрепление/рассинхронизация project participants и audit trail.
- **Направление исправления:** row lock + conditional `pending → accepted`, уникальный DB-инвариант и idempotent повтор того же субъекта.
- **Регрессия:** конкурентный PostgreSQL test с двумя соединениями и утверждением единственного победителя.

### AUTH-007 — потеря verification email блокирует основной аккаунт

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** High.
- **Сценарий и инвариант:** mail provider failure после регистрации; пользователь должен иметь публичный безопасный путь повторно отправить verification.
- **Файлы:** `prohelper/app/Services/Auth/JwtAuthService.php:745-755`; `prohelper/routes/api/v1/landing/auth.php:27-35`.
- **Воспроизведение:** зарегистрировать аккаунт при ошибке mail transport, затем попытаться войти и вызвать resend без access token.
- **Evidence:** email exception проглатывается после commit; login требует verified email; resend размещён в authenticated route group.
- **Эффект:** пользователь навсегда заперт без обращения в поддержку.
- **Направление исправления:** публичный anti-enumeration resend с rate limit либо короткая verification session; delivery вынести в наблюдаемую post-commit очередь.
- **Регрессия:** mail failure, unknown email, rate-limit, resend without login и successful verification tests.

### AUTH-008 — регистрация не идемпотентна при timeout/retry

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** High.
- **Сценарий и инвариант:** повтор идентичной регистрации после потери ответа; повтор должен вернуть исходный результат без дубля и без ложного конфликта.
- **Файлы:** `prohelper/app/Services/Auth/JwtAuthService.php:510-755`; landing registration route/middleware.
- **Воспроизведение:** оборвать соединение после DB commit, затем повторить запрос с тем же `Idempotency-Key`.
- **Evidence:** CORS разрешает `Idempotency-Key`, но route/service не сохраняют idempotent result; post-commit invitation/sync/notifications/mail остаются синхронными.
- **Эффект:** пользователь видит «email уже занят» после фактически успешной регистрации; возможны повторные side effects.
- **Направление исправления:** idempotency key с actor/request fingerprint и сохранённым response; post-commit effects через outbox/queue с дедупликацией.
- **Регрессия:** response-loss, concurrent duplicate key, mismatched payload и post-commit retry tests.

### AUTH-009 — DaData autocomplete создаёт request storm и stale responses

- **Класс:** подтверждённый дефект.
- **Severity:** Medium.
- **Сценарий и инвариант:** ввод организации; на серию клавиш должен приходиться ограниченный актуальный запрос, старый ответ не должен заменять новый.
- **Файлы:** `prohelper_land/src/components/ui/AutocompleteInput.tsx:48-67`; `prohelper_land/src/hooks/useDaData.ts:114-141`.
- **Воспроизведение:** быстро вводить название в Safari при включённом network log.
- **Evidence:** вызов `onSearch` на каждое изменение без debounce/abort/dedupe/sequence guard; production зафиксировал 26 OPTIONS за 7 секунд.
- **Эффект:** перегрузка API/DaData, лишние preflight, нестабильные подсказки и усиление CORS-инцидента.
- **Направление исправления:** debounce 250–400 мс, `AbortController`, latest-request guard, короткий cache и minimum query length.
- **Регрессия:** fake-timer test количества вызовов, out-of-order responses, abort/unmount и Safari browser test.

### AUTH-010 — UI показывает технический `Load failed`

- **Класс:** подтверждённый дефект.
- **Severity:** Medium.
- **Сценарий и инвариант:** network/CORS/timeout при submit; пользователь получает бизнес-понятное сообщение и безопасный retry.
- **Файлы:** `prohelper_land/src/utils/api.ts:443-460`; `prohelper_land/src/pages/RegisterPage.tsx:253-277`.
- **Воспроизведение:** заблокировать preflight или оборвать сеть в Safari, отправить форму.
- **Evidence:** raw `fetch` без timeout/transport normalization; UI выводит произвольный `err.message`; screenshot содержит `Load failed`.
- **Эффект:** непонятная ошибка, рост повторных submit и обращений в поддержку.
- **Направление исправления:** типизированная transport error taxonomy, timeout, человекочитаемые переводы и correlation ID без технического текста.
- **Регрессия:** MSW tests для CORS-like rejection, offline, timeout, 409/422/429/500 и повторного submit.

### AUTH-011 — web refresh может отозвать сессию при гонке вкладок

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** Medium.
- **Сценарий и инвариант:** две вкладки обновляют один refresh cookie; штатная конкурентность не должна выглядеть как replay-атака и отзывать всю сессию.
- **Файл:** `prohelper/app/Services/Auth/WebAuthTokenService.php:156-269`.
- **Воспроизведение:** синхронно вызвать refresh из двух вкладок с одним cookie.
- **Evidence:** backend использует lock и rotation; второй запрос после mismatch может попасть в replay revoke, а frontend single-flight ограничен одним JS context.
- **Эффект:** случайный logout при обычной многовкладочной работе.
- **Направление исправления:** безопасное окно/grace с повторной выдачей уже рассчитанного результата либо межвкладочная координация при сохранении replay protection.
- **Регрессия:** concurrent refresh integration test и Playwright multi-tab scenario.

### AUTH-012 — согласие с условиями не подтверждается backend

- **Класс:** подтверждённый дефект.
- **Severity:** Medium.
- **Сценарий и инвариант:** регистрация с обязательным consent; сервер должен валидировать и сохранять версию, время и источник согласия.
- **Файлы:** `prohelper_land/src/pages/RegisterPage.tsx:68`, `:186`, `:229-249`; `prohelper/app/Http/Requests/Auth/RegisterRequest.php:38`.
- **Воспроизведение:** вызвать registration API напрямую без consent fields.
- **Evidence:** checkbox проверяется только клиентом; FormData не содержит consent; backend не требует `accepted` и не сохраняет evidence.
- **Эффект:** отсутствует надёжное юридическое доказательство согласия; обход UI простым API-вызовом.
- **Направление исправления:** server-side `accepted`, versioned policy identifier, timestamp и immutable audit record.
- **Регрессия:** contract tests для missing/false/valid consent и сохранённой версии.

### AUTH-013 — invitation token, email и exception details попадают в логи/ответы

- **Класс:** подтверждённый дефект.
- **Severity:** High.
- **Сценарий и инвариант:** invitation validate/accept/decline errors; секреты и PII не должны логироваться или возвращаться клиенту.
- **Файлы:** `prohelper/app/Http/Controllers/Api/V1/Customer/InvitationController.php:51-54`, `:90-94`, `:122-126`, `:153-157`, `:176-183`; `prohelper/routes/api/v1/customer.php:42-46`.
- **Воспроизведение:** вызвать invalid/expired invitation branches и проверить structured logs/API response.
- **Evidence:** raw token/email и exception message включаются в log context; часть ветвей возвращает технический текст. Token позволяет выполнить unauthenticated decline.
- **Эффект:** log-reader получает bearer-like capability и PII; возможен отказ от приглашения и раскрытие внутренней реализации.
- **Направление исправления:** логировать invitation ID и необратимый token fingerprint/короткий prefix, редактировать PII, возвращать только доменные `trans_message`.
- **Регрессия:** log-capture tests без raw token/email и response contract tests без exception message.

### AUTH-014 — autocomplete не реализует доступный combobox contract

- **Класс:** подтверждённый дефект.
- **Severity:** Medium.
- **Сценарий и инвариант:** keyboard/screen-reader selection организации; control должен сообщать роль, состояние, active option и loading/error.
- **Файл:** `prohelper_land/src/components/ui/AutocompleteInput.tsx:107+`.
- **Воспроизведение:** пройти поле клавиатурой и screen reader/axe.
- **Evidence:** отсутствуют `role=combobox/listbox/option`, `aria-expanded`, `aria-controls`, `aria-activedescendant`, live status; частичная клавиатурная логика не компенсирует семантику.
- **Эффект:** пользователи assistive technologies не могут надёжно понять и выбрать организацию.
- **Направление исправления:** WAI-ARIA combobox pattern, предсказуемая клавиатура, live loading/error и сохранение ручного ввода.
- **Регрессия:** axe, Testing Library keyboard tests и ручная screen-reader проверка.

### AUTH-015 — auth validation/messages и response contracts неоднородны

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** Medium.
- **Сценарий и инвариант:** одинаковая ошибка auth во всех клиентах; пользовательский текст переводим, envelope стабилен, технические детали скрыты.
- **Файлы:** `prohelper/app/Http/Requests/Auth/RegisterRequest.php:112-144`; `prohelper/app/Http/Middleware/CorsMiddleware.php`; auth responses landing/customer/mobile/admin.
- **Воспроизведение:** сравнить validation, CORS denial и auth errors между аудиториями.
- **Evidence:** RegisterRequest содержит hardcoded Russian messages вместо `trans_message`; CORS denial использует текст административной панели для любого audience; envelopes различаются.
- **Эффект:** разные UI обрабатывают один сбой по-разному; локализация и аналитика ошибок ненадёжны.
- **Направление исправления:** единая taxonomy/code/message/correlation-id при сохранении профильных Response classes; все пользовательские сообщения через `trans_message`.
- **Регрессия:** snapshot/contract tests всех auth audiences и translation completeness test.

### AUTH-016 — критические негативные и конкурентные сценарии не покрыты

- **Класс:** пробел тестового покрытия.
- **Severity:** Medium.
- **Сценарий и инвариант:** изменения CORS/auth не должны выпускаться без автоматической проверки origin matrix, concurrency и failure recovery.
- **Файлы:** backend `CorsMiddlewareTest`, `AuthRouteStackHardeningTest`; frontend registration/customer/mobile/admin auth test suites.
- **Воспроизведение:** сопоставить существующие тесты с матрицами разделов 7–9.
- **Evidence:** CORS tests покрывают admin/lk/public, но не customer; отсутствуют inactive membership, role rollback, concurrent invitation, mail failure/public resend, response-loss/idempotency и multi-tab refresh; DaData concurrency/transport errors также не покрыты.
- **Эффект:** подтверждённые дефекты могли пройти CI и deployment без сигнала.
- **Направление исправления:** обязательный table-driven contract suite, PostgreSQL concurrency tests, MSW/browser auth scenarios и post-deploy smoke.
- **Регрессия:** сама перечисленная suite становится release gate.

### AUTH-017 — customer access token хранится в `sessionStorage`

- **Класс:** подтверждённая архитектурная несогласованность.
- **Severity:** Medium.
- **Сценарий и инвариант:** browser token storage; успешная XSS не должна автоматически давать долговременный bearer token.
- **Файл:** `prohelper_customers/src/shared/api/storage.ts:22-55`.
- **Воспроизведение:** выполнить JS в origin customer и прочитать `sessionStorage`.
- **Evidence:** customer client сохраняет JWT в Web Storage, тогда как modern main/admin держат access в памяти и refresh в HttpOnly cookie.
- **Эффект:** XSS получает токен и может вынести его за пределы вкладки; модель безопасности клиентов расходится.
- **Направление исправления:** перевести customer на отдельную modern web-auth audience: memory-only access + `__Host-` HttpOnly refresh cookie; до миграции усилить CSP и исключить сторонние script sinks.
- **Регрессия:** browser test отсутствия auth token в local/session storage, cookie flags и CSP smoke.

### AUTH-018 — CORS denial сообщает неверный контекст

- **Класс:** подтверждённый дефект.
- **Severity:** Low.
- **Сценарий и инвариант:** запрещённый origin для landing/customer; серверная диагностика должна идентифицировать CORS policy/audience без ложной ссылки на административную панель.
- **Файл:** `prohelper/app/Http/Middleware/CorsMiddleware.php`.
- **Воспроизведение:** выполнить disallowed-origin preflight к landing или customer endpoint вне браузера и прочитать body.
- **Evidence:** общий denial message относится к административной панели независимо от классифицированной audience.
- **Эффект:** ошибочная диагностика в API clients/support; browser всё равно скрывает body из-за отсутствия ACAO.
- **Направление исправления:** безопасный нейтральный translated message и стабильный machine code `cors_origin_forbidden`, без раскрытия allowlist.
- **Регрессия:** отрицательные contract tests для каждой audience.

## 11. Что доказанно работает корректно

- Основная регистрация создаёт user, organization, membership и `organization_owner` внутри одной DB transaction (`JwtAuthService::register`, begin около строки 510, role около 607, commit около 644).
- База защищает активный case-insensitive email и организационный tax identifier от обычных дублей; гонка должна завершаться constraint error, а не двумя строками.
- DaData не является серверной зависимостью регистрации: ручные реквизиты можно отправить без успешной подсказки.
- Пароль сохраняется через hashed cast модели.
- Forgot-password отвечает одинаково для существующего и неизвестного email, снижая enumeration.
- Reset token действует 60 минут; service использует transaction и row locks, удаляет token, меняет пароль и отзывает auth sessions.
- Reset frontend URL фиксирован конфигурацией; пользовательский redirect parameter не используется.
- Email verification использует signed URL на 60 минут с user id и email hash; повтор после успешного подтверждения идемпотентен.
- Modern main/admin auth использует HMAC-bound audience, короткий access, защищённый refresh cookie и server-side revoke.
- Logout modern web auth действительно отзывает auth session/cache, а не только удаляет локальный token.
- CORS не использует несовместимую комбинацию wildcard + credentials; разрешённые origins отражаются точно и получают `Vary: Origin`.
- Mobile хранит токены через `flutter_secure_storage` и имеет in-process single-flight refresh.
- Main/admin access tokens не сохраняются в Web Storage.
- Invitation token генерируется криптографически случайным, имеет срок и status; повторный accept тем же допустимым субъектом обрабатывается идемпотентно.

## 12. Пробелы evidence и границы аудита

- Не создавались тестовые пользователи, организации, приглашения или токены; write-path production не выполнялся.
- Не отправлялись формы регистрации, reset и invitation acceptance, поэтому доменные write-side выводы основаны на code trace и безопасных логах.
- Не проводился реальный multi-tab refresh с production cookie.
- Не проводились DAST/pentest, нагрузочный тест и эксплуатация XSS.
- Не подтверждено, в какой момент nginx redirect root `/register → lk/register` был развёрнут относительно инцидента.
- GlitchTip не является полным источником для управляемых 403, 409, 422 и browser-blocked CORS; access logs здесь авторитетнее.
- Visual responsive audit на реальных устройствах и полная screen-reader проверка не выполнялись.
- Уникальность телефона и ОГРН не закреплена как универсальный DB-инвариант; требуется отдельное бизнес-решение, допускаются ли общие контакты/реквизиты.

## 13. Приоритизированный план исправлений

### P0 — немедленная стабилизация production

1. Исправить customer CORS audience и закрепить origin matrix post-deploy smoke.
2. Формально зафиксировать канонический registration origin и проверить все root/www/lk маршруты, старые bundles и CDN cache.
3. Удалить raw invitation tokens/email/exception details из логов и клиентских ответов; оценить необходимость ротации ещё активных invitation tokens.
4. Ввести active-membership gate для legacy customer/mobile request и refresh.

### P1 — целостность регистрации и приглашений

5. Сделать customer initial role обязательной частью transaction.
6. Объединить invitation registration/acceptance в атомарный или idempotent workflow.
7. Сериализовать invitation acceptance row lock/conditional transition/DB invariant.
8. Добавить registration idempotency и вынести post-commit side effects в наблюдаемый outbox/queue.
9. Добавить публичный безопасный resend verification.

### P2 — устойчивость сессий и UX

10. Устранить multi-tab refresh race без ослабления replay protection.
11. Добавить DaData debounce/cancel/latest guard/cache.
12. Нормализовать network errors, timeout, retry и conflict states; исключить `Load failed`.
13. Валидировать и сохранять consent evidence на backend.
14. Перевести customer storage на modern cookie/memory auth.

### P3 — контракты и качество

15. Унифицировать auth error taxonomy и переводы.
16. Реализовать доступный combobox.
17. Сделать полную test matrix release gate и добавить browser post-deploy canary.

## 14. Обязательные acceptance criteria

- Каждый фактический production origin имеет ровно одну ожидаемую audience; неизвестные и cross-audience origins получают 403.
- Root registration либо никогда не загружает SPA и гарантированно redirect-ится на `lk`, либо официально разрешён в landing CORS; смешанного состояния нет.
- Customer login/refresh/logout проходят из customer origin и запрещены из unrelated origins.
- Двойной клик, timeout и повтор с тем же idempotency key создают ровно один аккаунт и возвращают исходный результат.
- Ошибка initial role или invitation acceptance не оставляет недоступный частичный аккаунт.
- Два конкурентных accept одного invitation дают одного победителя.
- Деактивация membership немедленно блокирует request, refresh и organization switch.
- Потерянный verification email восстанавливается без входа и без email enumeration.
- Десять быстрых изменений DaData input создают не более одного актуального запроса после debounce; старый ответ не отображается.
- Ни один auth response/log не содержит password, full token, cookie, reset hash, invitation token, raw exception или лишний email.
- Customer bearer token отсутствует в Web Storage после миграции.
- Safari и Chromium E2E покрывают CORS, offline, timeout, retry и multi-tab refresh.

## 15. Остаточные риски после рекомендуемых изменений

- CORS остаётся browser policy, а не security boundary; все endpoints обязаны сохранять полноценную authentication/authorization проверку.
- Компрометация origin через XSS позволяет действовать от имени пользователя даже при HttpOnly refresh cookie; необходимы CSP, безопасный rendering и dependency hygiene.
- Короткое grace-окно refresh требует точной модели replay, иначе оно либо вызывает ложные logout, либо ослабляет защиту.
- Email/SMS providers остаются внешней зависимостью; нужны retry, delivery status, rate limits и наблюдаемость без PII.
- Idempotency storage требует TTL, payload fingerprint, tenant isolation и защиты от накопления ключей.
- Изменения membership должны централизованно отзывать все связанные auth sessions, иначе проверки только на request оставляют race windows.

## 16. Ограничения реализации

- Не ослаблять authorization ради прохождения CORS.
- Не добавлять wildcard origins и не сочетать wildcard с credentials.
- Не возвращать технические exception messages пользователю.
- Не переносить обязательные server-side инварианты только во frontend.
- Не использовать Sanctum; auth-контур МОСТ остаётся JWT-based.
- Роли задавать через существующие RoleDefinitions и проверять через штатный authorization service.
- Любые DB-изменения оформлять отдельными миграциями и проверять только в штатном PostgreSQL test contour.

## 17. Подтверждение неизменности аудита

При сборе evidence не изменялись код, конфигурация, production-данные, файлы production, ветки, CI/CD и deployment; не выполнялись миграции, сидеры, reset/rollback, tinker с записью, рестарты или cache clear. Production использовался только через разрешённые read-only HTTP, GlitchTip wrapper и SSH `codex-ro`.

Единственное изменение после завершения аудита — настоящий Markdown-документ спецификации в отдельной локальной ветке backend. Продуктовый код и данные не изменены.

## 18. Дополнение по реализации и единственному финальному ревью

После завершения read-only аудита пользователь отдельно авторизовал реализацию плана. Историческое подтверждение неизменности в разделе 17 относится только к этапу аудита.

Ровно одно независимое финальное ревью выявило четыре дополнительные конкретизации уже зафиксированных проблем; новые AUTH-идентификаторы не создавались, чтобы не дублировать первопричины:

| Связанный пункт | Severity | Уточнение ревью | Реализованная защита | Регрессия |
|---|---|---|---|---|
| AUTH-002 | High | Первый post-deploy smoke подтвердил сохранение 403 для root-origin на register и DaData: middleware классифицировал весь landing namespace только как LK. | Только public registration и DaData endpoints принимают объединение точных `lk` + `public` origins с credentials; защищённые landing routes остаются изолированы. | Red→green middleware contract для register, DaData и отрицательного protected route; повторный production OPTIONS обязателен после hotfix deploy. |
| AUTH-003 | High | Modern web refresh проверял пользователя и auth-session, но не active membership из organization claim. | Refresh для LK/customer/admin отзывает server-side session и refresh state, возвращая `organization_membership_inactive`. | Inactive customer pivot и удалённый LK pivot блокируют refresh с 403 и revoke сессии. |
| AUTH-006 | High | `declineByToken` мог конкурентно перезаписать уже принятое приглашение. | Decline выполняется под `lockForUpdate`, допускает только атомарный переход `pending → declined`; accepted остаётся terminal. | PostgreSQL accept-vs-accept и accept-vs-decline race с управляемым lock-barrier. |
| AUTH-008 | Medium | Crash между внешним side effect и записью `completed` позволял повторить уведомление/интеграцию. | Для каждого шага введён durable state `executing/completed`; повторный worker не исполняет уже захваченный шаг, обычное исключение возвращает его в `pending`. | Повтор после имитации crash-state `executing` не отправляет email повторно. |
| AUTH-018 | Medium | Customer origin denial формировался через `LandingResponse`. | `VerifyWebRequestOrigin` выбирает `CustomerResponse` для customer audience. | Mutating customer auth request без Origin проверяет стандартизированный customer error contract. |

После закрытия замечаний ревью повторное независимое ревью не запускалось. Целевые PostgreSQL-регрессии и PHPStan изменённого блока прошли успешно; окончательный release/deploy evidence фиксируется в плане поставки и задаче `PHCODX-17`.
