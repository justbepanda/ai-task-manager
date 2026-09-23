# Implementation Plan — AI Tasks Manager

Дата: 2026-09-07  
Основа: `AGENTS.md` + операционный стиль `rbi2025` (Make + Docker Compose + Laravel).

## 0. Текущее состояние репозитория

| Есть | Нет |
|------|-----|
| Laravel 13 + Docker/Make (Phase 0) | IMAP sync, AI analysis, Inbox UI |
| Phase 1: enums, models, Project CRUD | Phases 2–8 |

**Конвенции rbi2025, которые сохраняем:**

- `Makefile` с `init-env`, `up`/`up-backend`, `down`, `composer-install`, `clear-cache`, `php`/`db` shells, `docker-exec` / `docker-compose-exec`
- Compose: `web` (nginx) + `php` + `db` + `redis` + `mailpit`
- Контейнеры: `${APP_NAME}-*`, сеть `${APP_NAME}-network`
- Workdir PHP: `/var/www`
- nginx → `php:9000`, root `/var/www/public`
- `.env` / `.env.example` подхватываются Make через `include`

**Отличия от rbi2025 (осознанные):**

| rbi2025 | ai-tasks-manager | Почему |
|---------|------------------|--------|
| Private image `docker.kelnik.pro/php/php-fpm:8.5` | Собственный `Dockerfile` на `php:8.4-fpm` | Нет доступа к private registry |
| MariaDB 10.5.8 | MySQL 8.4 | AGENTS.md: MySQL |
| RabbitMQ, Chromium, Node | Не в MVP | Не нужны для гипотезы |
| `QUEUE_CONNECTION=sync` | `redis` + сервисы `queue` / `scheduler` | AGENTS: Queue + Scheduler обязательны |
| Orchid + packages/* | Orchid admin только для MVP UI | Phase 7: Dashboard/Inbox/Projects/Actions |
| sync-db / rsync storage | Нет | Greenfield, нет remote dev DB |
| PHPUnit в `tests/backend` | PHPUnit в `tests/` | Стандарт Laravel 13 skeleton |

**Стек версий:**

- PHP **8.4** (8.5 отклонён: `ext-imap` больше не bundled в официальном образе; imap ставится через PECL)
- Laravel **13.x**
- Orchid Platform **^14.53**
- MySQL **8.4**
- Redis **7**
- nginx **1.27-alpine**
- LLM: **Hermes** → `LlmProvider` → **Google Gemini Flash** (смена модели без смены домена)

**Phase 0 — DONE:** Docker/Make подняты, `http://localhost` → 200, миграции и example-тесты ок.

---

## 1. Инфраструктура (сейчас)

```text
ai-tasks-manager/
├── Makefile
├── docker-compose.yml
├── Dockerfile                 # php-fpm 8.5 + ext (imap, redis, pdo_mysql, …)
├── docker/
│   ├── conf/nginx|php|mysql|ssl
│   ├── data/  (gitignored)
│   └── logs/  (gitignored)
├── .env.example               # APP, DB, Redis, Yandex IMAP, Gemini
├── AGENTS.md
├── docs/IMPLEMENTATION_PLAN.md
└── (Laravel app after create-project)
```

Make-команды (как rbi + полезные для MVP):

- `make install` / `make install-backend`
- `make up` / `make up-backend` / `make down`
- `make composer-install` / `make clear-cache`
- `make migrate` / `make test` / `make artisan CMD="..."`
- `make php` / `make db`
- `make queue` / `make scheduler` (поднять worker-сервисы)

---

## 2. Доменная модель (Phase 1+)

### Таблицы / модели

| Entity | Назначение |
|--------|------------|
| `users` | Пользователь системы (MVP: один, без жёсткой привязки) |
| `projects` | id, name, description, status, timestamps |
| `project_instructions` | Свободный текст инструкций для AI (отдельно от system prompt) |
| `project_matching_rules` | Детерминированные правила: emails, domains, subject patterns, technical sources, keywords (JSON columns / typed rows) |
| `conversations` | Логическая переписка + `agent_state` (сжатая память для Hermes) |
| `project_tasks` | Задачи проекта: Redmine/Jira key или email_thread; `messages.project_task_id` |
| `messages` | Нормализованное входящее сообщение (`source`, `external_id` unique, sender/`sender_role`, recipients/cc, subject, body, received_at, headers/metadata, classification, processing_status, project_id nullable, project_task_id, conversation_id, `ai_summary`, `agent_facts`) |
| `mail_filter_settings` | Singleton (id=1): глобальный чёрный список → classification=`ignored` |
| `message_raws` (опц.) | Сырые IMAP-данные / headers blob |
| `events` | Результат понимания: event_type, summary, confidence, message_id, project_id, payload |
| `manager_actions` | suggested_action, draft_reply, status (pending/approved/edited/rejected/completed), reason |
| `project_state_logs` | История изменений состояния проекта |
| `processing_logs` | Журнал пайплайна по message (для отладки решений) |
| `hypothesis_metric_events` | События метрик гипотезы §21 (`HypothesisMetricsRecorder`) |
| `client_companies` | Клиенты (компании): name, description, notes |
| `client_contacts` | Контакты клиента (ФИО, emails JSON, telegram, phone) → company |
| `executors` | Исполнители (сотрудники): ФИО, emails, is_active |
| `client_company_project` / `client_contact_project` / `executor_project` | M2M со справочниками |

### Enums (PHP backed / DB string)

- Classification: `client`, `technical`, `newsletter`, `personal`, `ignored`, `unknown`
- Message processing: `pending`, `classified`, `analyzed`, `applied`, `failed`, `needs_manual`
- Event types (MVP): `client_question`, `client_request`, `client_reported_bug`, `client_approved`, `client_rejected`, `task_created`, `task_updated`, `task_completed`, `technical_notification`, `information_only`
- ManagerAction type: `no_action`, `review`, `reply`, `follow_up`
- ManagerAction status: `pending`, `approved`, `edited`, `rejected`, `completed`
- Project status: `active`, `paused`, `archived`

---

## 3. Сервисы и интерфейсы

```text
App\Mail\Contracts\MailProvider
  └─ YandexMailProvider          # IMAP

App\Hermes\ProcessIncomingMessage               # full pipeline: tools → optional LLM → apply
App\Hermes\Contracts\HermesAgent
  └─ DefaultHermesAgent                          # context → LlmProvider → HermesProposal
App\Hermes\Contracts\LlmProvider
  └─ GeminiAiAnalyzer                            # Gemini Flash (AiAnalyzer extends LlmProvider)
  └─ FakeAiAnalyzer                              # tests
App\AI\Contracts\AiAnalyzer                      # deprecated alias of LlmProvider

App\AI\Services\BuildMessageContext
App\Domain\Conversations\UpdateConversationAgentMemory  # message.agent_facts + conversation.agent_state after Hermes
App\AI\Services\AnalysisResultValidator
App\AI\Services\AnalyzeMessage                   # Hermes propose → validate → Event

App\Domain\Classification\MessageClassifier      # rules-first (ignored → technical → …)
App\Domain\Classification\MailFilterMatcher      # global blacklist → classification=ignored
App\Domain\Mail\MailFilterSettingService         # Orchid save lines→arrays + addBlockedEmail
App\Domain\Projects\ProjectMatcher               # deterministic candidates (+ Redmine subject)
App\Domain\Events\ApplyProjectEventService       # Hermes не пишет в Project напрямую
App\Domain\ManagerActions\ManagerActionService   # approve/edit/reject/complete
App\Domain\Metrics\HypothesisMetricsRecorder
App\Domain\Clients\ClientCompanyService
App\Domain\Clients\ClientContactService
App\Domain\Executors\ExecutorService
```

DTO:

- `NormalizedIncomingMessage`
- `MessageContext` (письмо + диалог + project + instructions + recent events + candidate_projects)
- `HermesProposal` (context + raw payload)
- `StructuredAgentResult` / `AnalysisResult` (validated Hermes JSON fields)

Config:

- `config/mail_providers.php` — Yandex IMAP
- `config/ai.php` — provider, model, confidence thresholds
- `config/classification.php` — technical patterns, Redmine subject senders

---

## 3b. Архитектура Hermes (целевая)

```text
Message
 ↓
Hermes Agent
 ├── classification
 ├── project identification
 ├── context retrieval
 ├── event detection
 ├── manager participation decision
 └── draft generation
 ↓
Structured Agent Result
 ↓
Laravel validation
 ↓
Project State / Manager Action
```

```text
Hermes → LlmProvider → Gemini Flash
```

Hermes **может**: читать письмо, контекст, искать проекты, анализировать, предлагать state/draft/ManagerAction.  
Hermes **не может**: отправлять/удалять почту, писать критичные данные без валидации, выполнять произвольные команды.

На MVP: `ProcessIncomingMessage` — единая точка входа Hermes (classify/match tools → optional LLM → Laravel apply). Детерминированные правила экономят LLM (§17).

---

## 4. Jobs / pipeline

```text
SyncYandexMailJob
  → PersistMessage (idempotent by source+external_id)
  → ClassifyMessageJob  (= Hermes ProcessIncomingMessage)
        ├── tools: classification + project identification
        ├── context retrieval
        ├── [if needs LLM] HermesAgent::propose → validate → Event
        └── [else] hermes_llm_skipped
  → ApplyEventJob
  → ManagerAction (if requires_manager)
```

`AnalyzeMessageJob` остаётся для ручного повторного AI (карточка письма / bulk attach).

Scheduler: `SyncYandexMailJob` каждые N минут. Orchid: «Синхронизировать почту».

---

## 5. Фазы реализации

| Phase | Содержание | Статус |
|-------|------------|--------|
| 0 | Docker/Make + Laravel 13 scaffold + plan | **DONE** |
| 1 | Models, migrations, Project CRUD, instructions, matching rules | **DONE** |
| 2 | Yandex IMAP + Message sync + scheduler/queue | **DONE** |
| 3 | Classification + project candidates | **DONE** |
| 4 | Hermes + LlmProvider (Gemini) + validation | **DONE** (Hermes layer introduced) |
| 5 | Event + apply project state | **DONE** |
| 6 | ManagerAction + draft approve/edit/reject | **DONE** |
| 7 | Orchid admin: Dashboard, Inbox, Projects, Actions | **DONE** |
| 8 | Real mailbox + metrics | **DONE** (автоматизация) |
| 9 | Directories: Clients / Contacts / Executors | **DONE** |

### Phase 1 — DONE (кратко)

Enums, migrations/models (projects, instructions, matching rules, conversations, messages, events, manager_actions, processing_logs, analysis_corrections), `config/ai.php` + `config/mail_providers.php`, stub `AiAnalyzer`/`MailProvider` bindings, Project domain via services, feature tests.

### Phase 2 — DONE (кратко)

Native PHP IMAP (`YandexMailProvider` + `ImapMailbox` / `ImapMessageMapper`), idempotent `PersistIncomingMessage` (`source`+`external_id`), `mail_sync_states` cursor **per folder** (`provider`+`folder`; first run: newest 100 per folder), `listSyncFolders()` syncs all IMAP folders by default (user labels + Trash/Корзина; exclude spam/drafts/sent via `YANDEX_IMAP_FOLDER_EXCLUDE` with proper UTF7-IMAP→UTF-8 decode), mailbox names with comma/space are quoted on open, empty IMAP fetches are skipped (`SyncMailService`) / purged (`messages:purge-empty`), `SyncMailService` → `SyncYandexMailJob` / `mail:sync`, schedule via `config('mail_providers.sync_interval_minutes')`, stub `ClassifyMessageJob`, `FakeMailProvider` + feature/unit tests. No webklex.

### Phase 3 — DONE (кратко)

Rules-first `MessageClassifier` + `ProjectMatcher` + orchestrator `ClassifyAndMatchMessage` (wired in `ClassifyMessageJob`). Order: **ignored** (global `MailFilterMatcher` / `mail_filter_settings`) → technical → newsletter → personal → client → unknown. Config `config/classification.php`. Processing logs `classified` / `project_matched`. `AnalyzeMessageJob` only for `client` (or `unknown` with project); skipped for technical/newsletter/personal/**ignored**. Feature/unit tests without IMAP/Gemini.

### Phase 4 — DONE (кратко)

`AiAnalyzer` (Gemini HTTP + `FakeAiAnalyzer`), `BuildMessageContext`, `AnalysisResultValidator`, orchestrator `AnalyzeMessage` → persist `Event` + message `ai_*` / `agent_facts` / `analyzed`, `UpdateConversationAgentMemory` → `conversations.agent_state`, `ProcessingLog` `ai_analyzed`, stub `ApplyEventJob`. AI never mutates Project state or sends mail. PHPUnit with Fake only (no live Gemini).

### Phase 5 — DONE (кратко)

`ApplyProjectEventService` applies Events via confidence thresholds (`config/ai.php`): high (≥ `auto_apply`) + `project_id` → update Project `last_event_*` / optional pause→active, write `project_state_logs`, set `event.applied_at` + message `applied`. Low/medium → skip project mutation, `needs_manual`, leave `applied_at` null. **Creates pending `ManagerAction`** when `requires_manager` or `suggested_action` ∈ reply/review/follow_up (idempotent per `event_id`). `ApplyEventJob` wired. Artisan `messages:reprocess` (`--reclassify-manual` after adding projects/rules) re-dispatches Classify / Analyze / Apply for stuck mail.

### Phase 6 — DONE (кратко)

`ManagerActionService` (approve / edit / reject / complete) with transition guards (`ValidationException`). Approve → `approved` + `final_reply` (draft copy if unset), `decided_at`; **does not send email**. Edit → `edited` + `analysis_corrections.draft_reply`. Reject → `rejected` + corrections for `suggested_action` / `requires_manager`. Complete → `completed` after manual handling. Feature tests for transitions.

### Phase 7 — DONE (кратко)

**Orchid Platform ^14.53** admin at `/admin` (`DASHBOARD_PREFIX`). UI language: **Russian** (`APP_LOCALE=ru`). Blade Project/ManagerAction UI **удалён** (no BC). Screens: `DashboardScreen`, `MessageList/View`, `MessageProjectAssignScreen` (`platform.messages.assign` — rules/`ProjectMatcher`), `MessageProjectAssignLlmScreen` (`platform.messages.assign-llm` — `SuggestUnassignedMessageAssignmentsWithLlm` + session `AssignmentBoardPlan`, attach via `BulkAttachMessagesToProject`), `ProjectList/Edit`, `ManagerActionList/View`, `MailFilterSettingEditScreen`, `AppSettingEditScreen` (`platform.settings` — Redmine auto-create + LLM auto-assign + confidence thresholds), ignored inbox (`platform.messages.ignored`) — thin UI, fat services (`ProjectService`, `MessageManualOverrideService`, `ManagerActionService`, `MailFilterSettingService`, `AppSettingService`, `AutoCreateProjectFromIncomingMail`, `AutoAssignProjectViaLlm`). Public `/` — empty welcome. Seeds: `AdminUserSeeder` (`developer@kelnik.ru` / `developer`), `MailFilterSettingSeeder`, `AppSettingSeeder` (auto-create/auto-assign **on** by default), optional `ProjectSeeder` / `DemoMailboxSeeder`. Makefile: `.PHONY` includes `artisan` (fixes clash with root `artisan` file), `make seed`, `make fresh-seed`. `APP_URL=http://localhost`, `HTTP_PORT=80`.

### Phase 8 — DONE (кратко)

**Автоматизировано:** таблица `hypothesis_metric_events` + `HypothesisMetricsRecorder` (wire в Persist / ClassifyAndMatch / AnalyzeMessage / ManagerActionService / MessageManualOverrideService); `HypothesisMetricsReport` + artisan `metrics:hypothesis-report` / `mailbox:evaluate`; Orchid Dashboard блок гипотезы + экран «Метрики гипотезы»; журнал обработки и `analysis_corrections` на `MessageViewScreen` (sanitize секретов); AI debug в `ProcessingLog` (`ai_response` truncated, `ai_model`); колонка `requires_manager` во входящих; миграция `mail_filter_settings` (`2026_09_08_000002`) + subjects→text (`000001`); `messages.sender_role` + `config/sender_roles.php`; `ImapMessageMapper` unwrap raw MIME/base64 (`messages:repair-bodies`).

**Ручная проверка на реальном ящике (не заменяется автотестами):** заполнить Yandex creds → `mail:sync --sync` → при необходимости `messages:reprocess` → `metrics:hypothesis-report` / `mailbox:evaluate` → менеджер проверяет корректность событий и draft в Orchid.

### Phase 9 — DONE (кратко)

Справочники **Клиенты** (`ClientCompany` + `ClientContact`) и **Исполнители** (`Executor`): миграции + pivots с `projects`, domain services, Orchid CRUD (RU), `ClientSeeder` / `ExecutorSeeder`, feature tests (attach contact→company sync, detach keeps company, executor attach, fullName, Orchid screens).

---

## 6. Тестирование

- PHPUnit/Pest как в свежем Laravel 13 (предпочтительно Pest, если scaffold его ставит; иначе PHPUnit как rbi).
- Fake `MailProvider` + Fake `AiAnalyzer`.
- Изолированная SQLite in-memory для Feature-тестов.
- Обязательные сценарии — AGENTS §18.

---

## 7. Риски / явные отклонения

1. **Private PHP image недоступен** → свой Dockerfile (поведение контейнеров то же).
2. **Node/Vue pipeline rbi не копируем** → Orchid admin для MVP UI; публичный `/` пустой.
3. **Blade admin удалён в Phase 7** → Orchid `/admin` (no BC).
4. Gemini — первый `AiAnalyzer`; OpenAI/Claude позже через тот же интерфейс.
5. Makefile: файл `artisan` конфликтовал с target → исправлено через `.PHONY` + `make artisan CMD=...`.

---

## 8. Definition of done для Phase 0

- [ ] `make install` поднимает web/php/db/redis
- [ ] Laravel 13 отвечает на `APP_URL`
- [ ] `make migrate` / `make test` работают
- [ ] В `.env.example` есть Yandex IMAP + Gemini placeholders
- [ ] Этот план в репозитории
