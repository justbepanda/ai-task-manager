<?php

namespace Database\Seeders;

use App\Domain\Projects\ProjectService;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Upsert matching rules for real client projects seen in the mailbox
 * (Tekta ЛК bugs vs QA noise; Stavni / Gran Vista client threads).
 */
class ProjectMatchingEnrichmentSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ProjectService::class);

        $this->upsert($service, [
            'name' => 'Tekta',
            'description' => 'ЛК клиента/брокера и сайты Tekta Group.',
            'status' => ProjectStatus::Active,
            'instruction_body' => <<<'TXT'
Проект Tekta: личный кабинет и сайты девелопера.
«Сообщение о неисправности» / report-problems / баги стенда — рабочая почта, нужна привязка к проекту и задачам.
Автоматические проверки бронирования, брокер-туров и «Ваш список недвижимости» — игнор (глобальные mail filters), не проектная работа.
TXT,
            'client_emails' => '',
            'staff_emails' => '',
            'domains' => "tekta.com\ntekta.ru",
            'subject_patterns' => "Сообщение о неисправности",
            'keywords' => "Tekta\nТекта\nЛК клиента и брокера\nнеисправность\nreport-problems\nlk-dev",
            'technical_sources' => "lk@tekta.com\nlk-dev.tekta.ru",
            'custom_rules' => 'Не путать QA-бронь ЛК с багами «Сообщение о неисправности».',
        ]);

        $this->upsert($service, [
            'name' => 'STAVNI',
            'description' => 'Клиент Stavni Dom: сайты и инфраструктура.',
            'status' => ProjectStatus::Active,
            'instruction_body' => <<<'TXT'
Клиент STAVNI (stavni-dom.ru). Переписка про сайты, ВМ, колл-трекинг, договорённости после встреч — сюда.
TXT,
            'client_emails' => "yana.shukel@stavni-dom.ru",
            'staff_emails' => '',
            'domains' => "stavni-dom.ru",
            'subject_patterns' => '',
            'keywords' => "STAVNI\nStavni\nставни",
            'technical_sources' => '',
            'custom_rules' => '',
        ]);

        $this->upsert($service, [
            'name' => 'Gran Vista',
            'description' => 'Клиент Gran Vista: рендеры, презентации, контент сайта.',
            'status' => ProjectStatus::Active,
            'instruction_body' => <<<'TXT'
Клиент Gran Vista (granvista.ad@gmail.com). Переписка про RENDER, виллы, презентации и контент — сюда.
TXT,
            'client_emails' => "granvista.ad@gmail.com",
            'staff_emails' => '',
            'domains' => '',
            'subject_patterns' => '',
            'keywords' => "Gran Vista\nRENDER\nVILLA",
            'technical_sources' => '',
            'custom_rules' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsert(ProjectService $service, array $data): void
    {
        $existing = Project::query()->where('name', $data['name'])->first();

        if ($existing !== null) {
            $rule = $existing->matchingRule;
            $merged = $data;
            // Merge list fields so manual Orchid edits are not wiped by empty seed values.
            foreach (['client_emails', 'staff_emails', 'domains', 'subject_patterns', 'keywords', 'technical_sources'] as $field) {
                $incoming = $this->linesToList((string) ($data[$field] ?? ''));
                $current = is_array($rule?->{$field}) ? $rule->{$field} : [];
                $merged[$field] = implode("\n", array_values(array_unique(array_merge($current, $incoming))));
            }
            if (trim((string) ($existing->instruction?->body ?? '')) !== '') {
                $merged['instruction_body'] = (string) $existing->instruction->body;
            }
            $service->update($existing, $merged);

            return;
        }

        $service->create($data);
    }

    /**
     * @return list<string>
     */
    private function linesToList(string $raw): array
    {
        $lines = preg_split('/\R/u', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
