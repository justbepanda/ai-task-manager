<?php

namespace Database\Seeders;

use App\Models\MailFilterSetting;
use Illuminate\Database\Seeder;

class MailFilterSettingSeeder extends Seeder
{
    /**
     * Obsolete subject stop-words removed on seed (too broad / replaced by regex).
     *
     * @var list<string>
     */
    private const REMOVED_SUBJECT_STOP_WORDS = [
        'Оценка:',
        // Broken: required a char between «Студия» and «№», so «Студия № 222» never matched.
        'regex:/,\s*(?:\d+-комнатн|Студия\s+.+\s*№)/ui',
    ];

    /**
     * Redmine «Поддержка» + personal score notification: «Оценка: Имя Фамилия».
     * Does not match «Оценка: ЛК Брокера | …» on other projects (e.g. Tekta).
     */
    public const REDMINE_PODDERZHKA_OCENKA_REGEX = 'regex:/\[Поддержка\s*-[^\]]*\][^\n]*Оценка:\s+[А-ЯЁA-Z][а-яёa-zЁё]+(?:\s+[А-ЯЁA-Z][а-яёa-zЁё]+)+/u';

    /**
     * Real-estate LK booking confirmation subjects:
     * «Плашиннов …, 2-комнатная евро № 308» / «…, Студия № 222».
     * Does not match «Сообщение о неисправности».
     *
     * `.*?` (not `.+`) so «Студия №» with only whitespace before № still matches.
     */
    public const LK_BOOKING_SUBJECT_REGEX = 'regex:/,\s*(?:\d+-комнатн|Студия\s+.*?№)/ui';

    /**
     * Default global ignore list:
     * - Yandex ID / auth noise
     * - site lead forms (callback / booking / installment)
     * - ЛК registration / user-update automations (not project mail)
     * - real-estate LK QA: booking confirmations, broker tours, property lists
     * - calendar invites / Yandex 360 marketing
     * - Redmine «Поддержка» personal score notifications
     *
     * Do not use bare «Обратная связь» — that matches Redmine ticket subjects.
     * Do not block LK@tekta.com entirely — «Сообщение о неисправности» is project work.
     *
     * @return array{
     *     blocked_emails: list<string>,
     *     blocked_domains: list<string>,
     *     subject_stop_words: list<string>,
     *     body_stop_words: list<string>
     * }
     */
    public static function defaults(): array
    {
        return [
            'blocked_emails' => [
                'noreply@',
                'no-reply@',
                'mailer-daemon@',
                'postmaster@',
            ],
            'blocked_domains' => [
                'id.yandex.ru',
                'id.yandex.com',
                'passport.yandex.ru',
                'passport.yandex.com',
                'accounts.google.com',
                'facebookmail.com',
                'calendar.yandex.ru',
                '360.yandex.ru',
            ],
            'subject_stop_words' => [
                'пароль для приложения',
                'Вы создали пароль',
                'Код подтверждения',
                'Новый вход в аккаунт',
                'Восстановление пароля',
                'Security alert',
                // Website lead forms (RBI / Tekta / similar) — not project correspondence.
                'Заявка на обратный звонок',
                'Заказ обратного звонка',
                'Заявка на бронирование',
                'Заявка на рассрочку',
                'Заявка на обратную связь',
                'Новая заявка на обратную связь',
                // Product ЛК automations (not manager project work).
                'ЛК Брокера, регистрация',
                'ЛК Клиента, регистрация',
                'Зарегистрирован пользователь',
                'Обновление пользователя',
                // OOO / vacation auto-replies (not project work).
                'Автоматический ответ:',
                'Автоматический ответ',
                // Real-estate LK QA / stand checks (booking, broker tour, lists).
                'Новая заявка на брокер-тур',
                'Ваш список недвижимости',
                self::LK_BOOKING_SUBJECT_REGEX,
                self::REDMINE_PODDERZHKA_OCENKA_REGEX,
            ],
            'body_stop_words' => [
                'На это письмо не нужно отвечать',
                'Яндекс ID',
                'согласен с условиями оплаты и отмены бронирования',
                'Вы можете ознакомиться со списком в приложении',
            ],
        ];
    }

    public function run(): void
    {
        $defaults = self::defaults();
        $existing = MailFilterSetting::query()->find(1);

        if ($existing === null) {
            MailFilterSetting::query()->create([
                'id' => 1,
                ...$defaults,
            ]);

            return;
        }

        $subjectStopWords = $this->mergeList(
            $existing->subject_stop_words,
            $defaults['subject_stop_words'],
            caseSensitive: true,
        );
        $subjectStopWords = array_values(array_filter(
            $subjectStopWords,
            static fn (string $word): bool => ! in_array($word, self::REMOVED_SUBJECT_STOP_WORDS, true),
        ));

        $existing->fill([
            'blocked_emails' => $this->mergeList($existing->blocked_emails, $defaults['blocked_emails']),
            'blocked_domains' => $this->mergeList($existing->blocked_domains, $defaults['blocked_domains']),
            'subject_stop_words' => $subjectStopWords,
            'body_stop_words' => $this->mergeList($existing->body_stop_words, $defaults['body_stop_words'], caseSensitive: true),
        ]);
        $existing->save();
    }

    /**
     * @param  list<string>|null  $current
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function mergeList(?array $current, array $defaults, bool $caseSensitive = false): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($defaults, $current ?? []) as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $key = $caseSensitive ? $item : mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $caseSensitive ? $item : $key;
        }

        return $merged;
    }
}
