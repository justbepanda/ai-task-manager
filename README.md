# AI Tasks Manager

MVP ИИ-помощника менеджера проектов (Laravel 13 + Docker + Orchid admin).

PHP-образ: **8.4-fpm-bookworm** (8.5 отклонён: расширение `imap` больше не bundled в официальном образе — ставится через PECL).

## Быстрый старт

```bash
cp .env.example .env   # если ещё нет .env
make install           # build, up, composer, migrate, key
make fresh-seed        # migrate:fresh --seed (admin + demo mailbox)
```

Админка: [http://localhost/admin/login](http://localhost/admin/login)

Локальные credentials (только для local):

- email: `developer@kelnik.ru`
- password: `developer`

Публичный сайт: [http://localhost/](http://localhost/) — пустая страница.

## Основные команды Make

| Команда | Описание |
|---------|----------|
| `make install` | Сборка образов, запуск стека, composer, migrate, APP_KEY |
| `make up` | Запуск web, php, db, redis, mailpit, queue, scheduler |
| `make down` | Остановка всех контейнеров |
| `make php` | Shell в PHP-контейнере |
| `make migrate` | `php artisan migrate --force` |
| `make seed` | `php artisan db:seed --force` |
| `make fresh-seed` | `migrate:fresh --seed --force` |
| `make test` | `php artisan test` |
| `make artisan CMD="..."` | Произвольная artisan-команда (`.PHONY artisan` — не путать с файлом `artisan`) |

Пример:

```bash
make artisan CMD="messages:reprocess --reclassify-manual"
make artisan CMD="mail:sync --sync"
```

## Порты

| Сервис | Порт |
|--------|------|
| HTTP (nginx) | **80** (`http://localhost`) |
| HTTPS (nginx) | **443** |
| Mailpit UI | **8025** |
| MySQL (с хоста) | **33066** |

`APP_URL=http://localhost`, `HTTP_PORT=80`, `HTTPS_PORT=443`, `DASHBOARD_PREFIX=/admin`.

Внутри Docker-сети Laravel подключается к MySQL как `DB_HOST=db`, `DB_PORT=3306`.  
С хоста (IDE/клиент) используйте `127.0.0.1:33066` (`DB_HOST_PORT`).

Если порт 80 занят другим процессом — освободите его или временно задайте `HTTP_PORT` в `.env` и пересоздайте `web`.

## Документация

- `AGENTS.md` — требования MVP
- `docs/IMPLEMENTATION_PLAN.md` — план реализации

## Orchid admin

Язык интерфейса: **русский** (`APP_LOCALE=ru`, `APP_FALLBACK_LOCALE=ru`).

Разделы: Обзор, Входящие, Игнорируемые, **Распределение** (массовая привязка к проектам), Фильтры почты (чёрный список), Проекты, **Клиенты**, **Исполнители**, Действия менеджера, Метрики гипотезы, Пользователи/Роли.

URL распределения: `/admin/messages/assign` (также кнопка «Распределить по проектам» во входящих).

Доменная логика в сервисах; screens тонкие. AI **не** отправляет письма. Классификация `ignored` — глобальный blacklist (не LLM).

На карточке письма: журнал `processing_logs` и таблица `analysis_corrections` (секреты в details маскируются).

## Seeds

`DatabaseSeeder`:

1. `AdminUserSeeder` — Orchid admin
2. `ProjectSeeder` — реалистичные проекты + rules/instructions
3. `ClientSeeder` — клиенты (компании) + контакты, связь с проектами
4. `ExecutorSeeder` — исполнители, связь с проектами
5. `MailFilterSettingSeeder` — дефолтный чёрный список (Яндекс ID / noreply / security subjects)
6. `DemoMailboxSeeder` — демо-письма (`source=seed`, `external_id` с префиксом `seed:`), events, manager_actions, processing_logs

`db:seed` идемпотентен через `updateOrCreate`. `migrate:fresh --seed` очищает БД (включая реальный IMAP-импорт) — после этого можно снова `mail:sync`.

## Почта (Yandex IMAP)

В `.env` задайте логин и **пароль приложения** Яндекс.Почты (не обычный пароль аккаунта):

```env
YANDEX_IMAP_USERNAME=you@yandex.ru
YANDEX_IMAP_PASSWORD=xxxx-xxxx-xxxx-xxxx
YANDEX_IMAP_FIRST_SYNC_LIMIT=100
MAIL_SYNC_INTERVAL_MINUTES=5
```

Ручная синхронизация:

```bash
make artisan CMD="mail:sync"
make artisan CMD="mail:sync --sync"
```

Первый запуск импортирует только newest `YANDEX_IMAP_FIRST_SYNC_LIMIT` писем (по умолчанию 100), не весь ящик.

Переобработка после смены правил/проектов:

```bash
make artisan CMD="messages:reprocess --reclassify-manual"
```

## Phase 8 — реальный ящик и метрики гипотезы

1. Заполните Yandex IMAP credentials в `.env`.
2. Синхронизируйте почту:

```bash
make artisan CMD="mail:sync --sync"
```

3. При необходимости переобработайте сообщения:

```bash
make artisan CMD="messages:reprocess --reclassify-manual"
```

4. Снимите отчёт по метрикам §21:

```bash
make artisan CMD="metrics:hypothesis-report"
make artisan CMD="metrics:hypothesis-report --evaluate"
make artisan CMD="mailbox:evaluate"
```

События пишутся в `hypothesis_metric_events`. Сводка также на Dashboard и в разделе «Метрики гипотезы» в Orchid.
