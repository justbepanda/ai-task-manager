<?php

namespace Database\Seeders;

use App\Models\MailFilterSetting;
use Illuminate\Database\Seeder;

class MailFilterSettingSeeder extends Seeder
{
    public function run(): void
    {
        MailFilterSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'blocked_emails' => [
                    'noreply@',
                    'mailer-daemon@',
                    'postmaster@',
                ],
                'blocked_domains' => [
                    'id.yandex.ru',
                    'passport.yandex.ru',
                    'accounts.google.com',
                    'facebookmail.com',
                ],
                'subject_stop_words' => [
                    'пароль для приложения',
                    'Вы создали пароль',
                    'Код подтверждения',
                    'Новый вход в аккаунт',
                    'Восстановление пароля',
                    'Security alert',
                ],
                'body_stop_words' => [
                    'На это письмо не нужно отвечать',
                    'Яндекс ID',
                ],
            ],
        );
    }
}
