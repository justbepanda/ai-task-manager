<?php

declare(strict_types=1);

namespace App\Domain\Ops;

/**
 * Catalog of safe-to-run ops actions exposed in Orchid admin.
 *
 * @phpstan-type Action array{
 *     id: string,
 *     title: string,
 *     command: string,
 *     description: string,
 *     when: string,
 *     options: array<string, bool|string|int>,
 *     confirm: string|null,
 *     danger: bool
 * }
 */
final class AdminArtisanCatalog
{
    /**
     * @return list<Action>
     */
    public static function actions(): array
    {
        return [
            [
                'id' => 'mail_sync',
                'title' => 'Синхронизировать почту',
                'command' => 'mail:sync',
                'description' => 'Забирает новые письма из Яндекс.Почты по IMAP из всех папок (кроме спама/черновиков/отправленных; Корзина включена) и ставит их в очередь классификации (Hermes). Повторный запуск не создаёт дубликаты.',
                'when' => 'Когда нужно подтянуть почту вручную, не дожидаясь планировщика.',
                'options' => [],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'mail_sync_inline',
                'title' => 'Синхронизировать почту сейчас (без очереди)',
                'command' => 'mail:sync',
                'description' => 'То же, что синхронизация, но выполняется сразу в HTTP-запросе (без queue worker). Может подождать дольше.',
                'when' => 'Если worker не запущен, а письма нужны прямо сейчас.',
                'options' => ['--sync' => true],
                'confirm' => 'Запустить IMAP-синхронизацию прямо сейчас? Запрос может занять время.',
                'danger' => false,
            ],
            [
                'id' => 'reprocess',
                'title' => 'Дообработать застрявшие письма',
                'command' => 'messages:reprocess',
                'description' => 'Ставит в очередь: pending-классификацию, AI-анализ для клиентских без Event, применение неприменённых Event. Не трогает уже нормально обработанные письма.',
                'when' => 'После сбоя очереди или если письма «зависли» в pending / без события.',
                'options' => [],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'reprocess_manual',
                'title' => 'Переклассифицировать needs_manual',
                'command' => 'messages:reprocess',
                'description' => 'Сбрасывает письма со статусом «нужна ручная обработка» (без Event) в pending и заново гоняет классификацию/сопоставление с проектами. Удобно после добавления проектов и правил.',
                'when' => 'Добавили проекты/правила — и хотите пересчитать «ручные» письма.',
                'options' => ['--reclassify-manual' => true],
                'confirm' => 'Сбросить needs_manual письма и переклассифицировать?',
                'danger' => false,
            ],
            [
                'id' => 'reprocess_unassigned',
                'title' => 'Переклассифицировать без проекта',
                'command' => 'messages:reprocess',
                'description' => 'Сбрасывает письма без project_id (кроме ignored) в pending и заново гоняет полный Hermes: правила → Redmine-автосоздание → LLM-распределение → анализ (summary/event/manager). Нужно после включения галочек в Настройках или обновления кода/queue worker.',
                'when' => 'Включили автосоздание/LLM в Настройках, а письма уже классифицировались без проекта и полей AI.',
                'options' => ['--reclassify-unassigned' => true],
                'confirm' => 'Сбросить письма без проекта и прогнать полный пайплайн заново? Потребует LLM-запросы.',
                'danger' => false,
            ],
            [
                'id' => 'apply_mail_filters',
                'title' => 'Пересобрать игнорируемые',
                'command' => 'messages:reprocess',
                'description' => 'Проходит по уже импортированным письмам и применяет текущие «Фильтры почты»: совпадения → ignored, лишние ignored → обратно в обычную классификацию.',
                'when' => 'После изменения чёрного списка / стоп-слов в теме.',
                'options' => ['--apply-mail-filters' => true],
                'confirm' => 'Применить фильтры ко всем письмам в базе?',
                'danger' => false,
            ],
            [
                'id' => 'apply_mail_filters_dry',
                'title' => 'Пересобрать игнорируемые (проверка)',
                'command' => 'messages:reprocess',
                'description' => 'Dry-run: показывает, сколько писем затронула бы пересборка фильтров, без записи в БД.',
                'when' => 'Перед реальной пересборкой — понять масштаб.',
                'options' => ['--apply-mail-filters' => true, '--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'detect_follow_ups',
                'title' => 'Скан тишины (follow-up)',
                'command' => 'messages:detect-follow-ups',
                'description' => 'Ищет диалоги, где последнее письмо — клиентское и старше N дней (по умолчанию 3), и создаёт ManagerAction «Контроль» (follow_up). Не шлёт письма само.',
                'when' => 'Вручную, если не хотите ждать ежедневный scheduler.',
                'options' => [],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'detect_follow_ups_sync',
                'title' => 'Скан тишины сейчас (без очереди)',
                'command' => 'messages:detect-follow-ups',
                'description' => 'То же, что скан тишины, но сразу в этом запросе — сразу видно, сколько follow_up создано.',
                'when' => 'Нужен мгновенный результат без queue worker.',
                'options' => ['--sync' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'supersede_stale_actions',
                'title' => 'Схлопнуть устаревшие действия менеджера',
                'command' => 'manager-actions:supersede-stale',
                'description' => 'В каждой переписке (conversation / задача) оставляет только одно pending-действие — по самому новому письму. Остальные pending помечает выполненными с пометкой в заметках.',
                'when' => 'После накопления нескольких «Ожидает» по одной цепочке писем (как STAVNI), или разово после обновления логики.',
                'options' => [],
                'confirm' => 'Закрыть устаревшие pending ManagerAction в пользу самого нового по переписке?',
                'danger' => false,
            ],
            [
                'id' => 'supersede_stale_actions_dry',
                'title' => 'Схлопнуть устаревшие действия (проверка)',
                'command' => 'manager-actions:supersede-stale',
                'description' => 'Dry-run: сколько pending было бы закрыто, без записи.',
                'when' => 'Перед реальной кнопкой — понять масштаб.',
                'options' => ['--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'rebuild_tasks',
                'title' => 'Собрать задачи по письмам',
                'command' => 'tasks:rebuild',
                'description' => 'Создаёт/привязывает ProjectTask: Redmine/Jira по номеру в теме, клиентская почта — по диалогу (conversation).',
                'when' => 'После обновления с задачами или если у писем нет привязки к задаче.',
                'options' => [],
                'confirm' => 'Пересобрать задачи по существующим письмам?',
                'danger' => false,
            ],
            [
                'id' => 'apply_project_people',
                'title' => 'Определить менеджеров и исполнителей задач',
                'command' => 'projects:apply-people',
                'description' => 'По письмам для задач без менеджера: Redmine (Автор/Назначена) + LLM → менеджер/исполнители на ProjectTask. Существующий менеджер задачи не перезаписывается. Потребует LLM-запросы.',
                'when' => 'После появления LLM-людей или если у задач пустой менеджер/исполнители.',
                'options' => [],
                'confirm' => 'Прогнать определение менеджеров/исполнителей по задачам без менеджера? Будут вызовы LLM.',
                'danger' => false,
            ],
            [
                'id' => 'apply_project_people_dry',
                'title' => 'Определить менеджеров задач (проверка)',
                'command' => 'projects:apply-people',
                'description' => 'Dry-run: какие задачи без менеджера были бы обработаны, без записи и без LLM.',
                'when' => 'Перед реальной кнопкой — понять масштаб.',
                'options' => ['--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'merge_duplicate_projects',
                'title' => 'Объединить дубликаты проектов',
                'command' => 'projects:merge-duplicates',
                'description' => 'Находит активные проекты с одним и тем же именем в разном написании (GigaPlan ≈ Гигаплан) и сливает в проект с большим числом писем. Keywords/алиасы объединяются.',
                'when' => 'Появились латинский и кириллический варианты одного бренда.',
                'options' => [],
                'confirm' => 'Объединить найденные дубликаты проектов? Операция необратима для удалённых дублей.',
                'danger' => false,
            ],
            [
                'id' => 'merge_duplicate_projects_dry',
                'title' => 'Объединить дубликаты (проверка)',
                'command' => 'projects:merge-duplicates',
                'description' => 'Dry-run: какие пары проектов были бы объединены.',
                'when' => 'Перед реальным объединением.',
                'options' => ['--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'repair_bodies',
                'title' => 'Починить тела писем',
                'command' => 'messages:repair-bodies',
                'description' => 'Переразбирает subject/body из сохранённого сырого IMAP (HTML→текст, MIME-темы). Не качает почту заново.',
                'when' => 'В письмах «кракозябры» или HTML вместо текста.',
                'options' => [],
                'confirm' => 'Переразобрать тела писем из raw?',
                'danger' => false,
            ],
            [
                'id' => 'repair_bodies_dry',
                'title' => 'Починить тела (проверка)',
                'command' => 'messages:repair-bodies',
                'description' => 'Dry-run: сколько писем было бы обновлено при починке тел.',
                'when' => 'Перед реальным repair-bodies.',
                'options' => ['--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'purge_empty',
                'title' => 'Удалить пустые письма',
                'command' => 'messages:purge-empty',
                'description' => 'Удаляет письма без темы/отправителя/тела (сбой открытия IMAP-папки с запятой в имени) и сбрасывает курсор sync этих папок.',
                'when' => 'В админке появились «пустые» письма без данных.',
                'options' => [],
                'confirm' => 'Удалить пустые письма и сбросить sync-курсоры их папок?',
                'danger' => false,
            ],
            [
                'id' => 'purge_empty_dry',
                'title' => 'Удалить пустые (проверка)',
                'command' => 'messages:purge-empty',
                'description' => 'Dry-run: сколько пустых писем было бы удалено.',
                'when' => 'Перед реальным purge-empty.',
                'options' => ['--dry-run' => true],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'metrics_report',
                'title' => 'Отчёт метрик гипотезы',
                'command' => 'metrics:hypothesis-report',
                'description' => 'Печатает сводку экспериментальных метрик (§21): сколько писем, классификаций, draft, исправлений и т.д.',
                'when' => 'Нужна текстовая выгрузка метрик (также есть экран «Метрики гипотезы»).',
                'options' => [],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'mailbox_evaluate',
                'title' => 'Оценка реального ящика (Phase 8)',
                'command' => 'mailbox:evaluate',
                'description' => 'Чеклист Phase 8: достаточно ли данных для оценки гипотезы на реальной почте + ключевые счётчики.',
                'when' => 'После накопления реальных писем — понять, готов ли MVP к оценке.',
                'options' => [],
                'confirm' => null,
                'danger' => false,
            ],
            [
                'id' => 'db_fresh_seed',
                'title' => 'Очистить БД + seed',
                'command' => 'migrate:fresh',
                'description' => 'ПОЛНОСТЬЮ удаляет все таблицы и данные (включая курсор IMAP last_uid / mail_sync_states), заново накатывает миграции и seed: админ developer@kelnik.ru / developer и фильтры почты. Письма, проекты, действия в приложении пропадут. Сам ящик Яндекса не трогается: после следующего sync заново подтянутся последние N писем (YANDEX_IMAP_FIRST_SYNC_LIMIT, по умолчанию 100). После запуска нужно войти заново. Важно: контейнер queue должен быть запущен (docker compose up -d queue), иначе «Синхронизировать почту» только поставит job в Redis и письма не появятся — используйте «Синхронизировать почту сейчас (без очереди)».',
                'when' => 'Только на локальной/тестовой среде, когда нужно «начать с нуля» (как make fresh-seed).',
                'options' => ['--seed' => true, '--force' => true],
                'confirm' => 'УДАЛИТЬ ВСЮ базу и заново засеять? Письма/проекты в приложении исчезнут, курсор sync сбросится. Яндекс.Почта не очищается. Логин: developer@kelnik.ru / developer',
                'danger' => true,
            ],
        ];
    }

    /**
     * @return Action|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::actions() as $action) {
            if ($action['id'] === $id) {
                return $action;
            }
        }

        return null;
    }
}
