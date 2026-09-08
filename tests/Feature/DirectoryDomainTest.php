<?php

namespace Tests\Feature;

use App\Domain\Clients\ClientCompanyService;
use App\Domain\Clients\ClientContactService;
use App\Domain\Executors\ExecutorService;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Executor;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DirectoryDomainTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function attaching_contact_to_project_also_attaches_company(): void
    {
        $project = Project::factory()->create();
        $company = app(ClientCompanyService::class)->create([
            'name' => 'ООО Тест',
        ]);
        $contact = app(ClientContactService::class)->create($company, [
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'middle_name' => 'Иванович',
            'emails' => "a@example.com\nb@example.com",
            'project_ids' => [$project->id],
        ]);

        $this->assertTrue($contact->projects()->whereKey($project->id)->exists());
        $this->assertTrue($company->fresh()->projects()->whereKey($project->id)->exists());
        $this->assertSame(['a@example.com', 'b@example.com'], $contact->fresh()->emails);
    }

    #[Test]
    public function detaching_contact_keeps_company_project_link(): void
    {
        $project = Project::factory()->create();
        $company = app(ClientCompanyService::class)->create([
            'name' => 'АО Север',
            'project_ids' => [$project->id],
        ]);
        $contact = app(ClientContactService::class)->create($company, [
            'last_name' => 'Петрова',
            'first_name' => 'Анна',
            'project_ids' => [$project->id],
        ]);

        app(ClientContactService::class)->syncProjects($contact, []);

        $this->assertFalse($contact->fresh()->projects()->whereKey($project->id)->exists());
        $this->assertTrue($company->fresh()->projects()->whereKey($project->id)->exists());
    }

    #[Test]
    public function executor_can_be_attached_to_project(): void
    {
        $project = Project::factory()->create();
        $executor = app(ExecutorService::class)->create([
            'last_name' => 'Смирнов',
            'first_name' => 'Алексей',
            'emails' => 'dev@agency.local',
            'project_ids' => [$project->id],
            'is_active' => true,
        ]);

        $this->assertTrue($executor->projects()->whereKey($project->id)->exists());
        $this->assertSame(['dev@agency.local'], $executor->fresh()->emails);
    }

    #[Test]
    public function full_name_formats_trimmed_fio(): void
    {
        $contact = ClientContact::factory()->make([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'middle_name' => 'Иванович',
        ]);
        $this->assertSame('Иванов Иван Иванович', $contact->fullName());

        $executor = Executor::factory()->make([
            'last_name' => 'Орлова',
            'first_name' => 'Елена',
            'middle_name' => null,
        ]);
        $this->assertSame('Орлова Елена', $executor->fullName());

        $withoutMiddle = ClientContact::factory()->make([
            'last_name' => 'Кузнецов',
            'first_name' => 'Павел',
            'middle_name' => '  ',
        ]);
        $this->assertSame('Кузнецов Павел', $withoutMiddle->fullName());
    }

    #[Test]
    public function deleting_company_cascades_contacts(): void
    {
        $company = ClientCompany::factory()->create();
        $contact = ClientContact::factory()->create(['client_company_id' => $company->id]);

        app(ClientCompanyService::class)->delete($company);

        $this->assertDatabaseMissing('client_companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('client_contacts', ['id' => $contact->id]);
    }
}
