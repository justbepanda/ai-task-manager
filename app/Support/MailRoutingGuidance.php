<?php

namespace App\Support;

/**
 * Shared routing guidance for Hermes analysis and LLM project assignment.
 * Deterministic ignore still lives in MailFilterSettingSeeder — these rules cover edge cases.
 */
final class MailRoutingGuidance
{
    /**
     * Extra classification rules for Hermes / Gemini system prompt.
     */
    public static function hermesClassificationBlock(): string
    {
        return <<<'TXT'

- Компания делает сайты/ЛК для девелоперов недвижимости. Часть писем — автоматические проверки форм на стендах; их не путать с работой менеджера.
- classification=ignored для автоуведомлений продуктовых ЛК/сайтов (нет живого диалога):
  • подтверждение бронирования («согласен с условиями оплаты и отмены бронирования»; тема вида «ФИО, N-комнатная … № …» / «…, Студия № …»);
  • «Новая заявка на брокер-тур», «Ваш список недвижимости», выгрузки «в приложении к письму»;
  • типовые QA-заявки с тестовыми ФИО/телефонами без переписки.
- НЕ ignored:
  • Redmine/Jira (в т.ч. «(Обратная связь)» в теме тикета — это задача разработки);
  • «Сообщение о неисправности» / report-problems / баги ЛК — client или technical, проект ЛК (Tekta и аналоги);
  • живая переписка Re:/RE: с клиентом (рендеры, перенос ВМ, договорённости после встречи).
- Для ignored: requires_manager=false, suggested_action=no_action, draft_reply=null.
- Profitbase — платформа/инструмент, встречается на разных проектах. Не ставь project_id и не предлагай новый проект только из-за «Profitbase» в теме; нужны бренд клиента, домен или явный алиас проекта.
- «Автоматический ответ» / out-of-office без рабочего содержания → classification=ignored.
TXT;
    }

    /**
     * Extra rules for AutoAssign / SuggestUnassigned LLM prompts.
     */
    public static function projectAssignBlock(): string
    {
        return <<<'TXT'
- Автошум ЛК недвижимости (бронь «ФИО, квартира №», брокер-тур, «Ваш список недвижимости») — unmatched=true, не создавай проект.
- «Сообщение о неисправности» / report-problems от LK@tekta.com / lk-dev.tekta.ru → проект Tekta (или ближайший ЛК по keywords), если confidence достаточна.
- Живая клиентская переписка (stavni-dom.ru, granvista.ad@gmail.com и т.п.) → существующий проект по домену/email/имени; иначе proposed_name по бренду клиента, не unmatched без причины.
- Не вешай рассылки и QA-формы стенда на клиентские проекты.
- Profitbase (и аналоги: CRM/планировки как сервис) — общий инструмент на многих проектах. Нельзя назначать/создавать проект только из слова «Profitbase» в теме. Смотри бренд клиента / домен / APEX / Tekta / RBI и т.п. Автоответы («Автоматический ответ», out of office) — unmatched=true.
- Латиница и кириллица одного бренда — один проект (GigaPlan = Гигаплан). Не создавай дубликат: привяжи к существующему и добавь proposed_aliases (оба написания).
TXT;
    }
}
