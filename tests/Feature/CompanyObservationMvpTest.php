<?php

namespace Tests\Feature;

use App\Models\CompanyImprovement;
use App\Models\CompanyObservation;
use App\Models\CompanySense;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompanyObservationMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_member_can_record_manual_observation_in_jst(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:15:00', 'Asia/Tokyo'));
        [$user, $company] = $this->companyUser(OrganizationUser::ROLE_MEMBER);

        $response = $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->post(route('company-observations.store'), [
                'title' => '同じ内容の問い合わせが増えている',
                'body' => '今週、同じ要望を含む問い合わせが3件あった。',
                'occurred_on' => '2026-08-03',
                'source_type' => CompanyObservation::SOURCE_CUSTOMER,
                'source_name' => '問い合わせ窓口',
            ]);

        $observation = CompanyObservation::sole();

        $response->assertRedirect(route('company-observations.show', $observation));
        $this->assertSame($company->id, $observation->organization_id);
        $this->assertSame($user->id, $observation->observer_user_id);
        $this->assertSame('2026-08-03 09:15', $observation->observed_at->timezone('Asia/Tokyo')->format('Y-m-d H:i'));
        $this->assertSame(CompanyObservation::IMPORTANCE_UNREVIEWED, $observation->importance);
    }

    public function test_company_dialogue_can_record_that_more_observation_is_needed(): void
    {
        [$user, $company] = $this->companyUser(OrganizationUser::ROLE_OWNER);
        $observation = $this->observation($company, $user);

        $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->post(route('company-observations.respond', $observation), [
                'importance' => CompanyObservation::IMPORTANCE_UNCLEAR,
                'next_observation' => '来月も同じ要望が続くか確認する。',
            ])
            ->assertRedirect(route('company-observations.show', $observation));

        $observation->refresh();
        $this->assertSame(CompanyObservation::STATUS_WATCHING, $observation->status);
        $this->assertSame(CompanyObservation::IMPORTANCE_UNCLEAR, $observation->importance);
        $this->assertSame('来月も同じ要望が続くか確認する。', $observation->next_observation);
        $this->assertDatabaseCount('company_senses', 0);
    }

    public function test_observation_can_grow_through_sense_into_company_improvement(): void
    {
        [$user, $company] = $this->companyUser(OrganizationUser::ROLE_OWNER);
        $observation = $this->observation($company, $user);

        $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->post(route('company-observations.respond', $observation), [
                'importance' => CompanyObservation::IMPORTANCE_IMPORTANT,
                'interpretation' => '顧客ニーズが変化している兆候かもしれない。',
                'hypothesis' => '既存サービスで新しい要望を満たせていない。',
            ])
            ->assertRedirect(route('company-observations.show', $observation));

        $sense = CompanySense::sole();
        $this->assertDatabaseHas('company_observation_sense', [
            'company_observation_id' => $observation->id,
            'company_sense_id' => $sense->id,
            'relationship_type' => 'interprets',
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->post(route('company-observations.improvements.store', [$observation, $sense]), [
                'title' => '顧客ニーズの変化をサービスへ反映する',
                'desired_state' => '増えている要望へ一貫して対応できる状態',
                'expected_effect' => '相談から提案までの精度が上がる',
                'priority' => CompanyImprovement::PRIORITY_HIGH,
            ])
            ->assertRedirect(route('company-observations.show', $observation));

        $improvement = CompanyImprovement::sole();
        $this->assertSame(CompanyImprovement::STATUS_DISCOVERED, $improvement->status);
        $this->assertDatabaseHas('company_improvement_observation', [
            'company_improvement_id' => $improvement->id,
            'company_observation_id' => $observation->id,
            'relationship_type' => 'discovered_from',
        ]);
        $this->assertDatabaseHas('company_improvement_sense', [
            'company_improvement_id' => $improvement->id,
            'company_sense_id' => $sense->id,
            'relationship_type' => 'suggests',
        ]);
        $this->assertSame(CompanyObservation::STATUS_DEVELOPED, $observation->fresh()->status);

        $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->get(route('company-observations.show', $observation))
            ->assertOk()
            ->assertSee('COMPANY DIALOGUE')
            ->assertSee('顧客ニーズが変化している兆候かもしれない。')
            ->assertSee('COMPANY IMPROVEMENT')
            ->assertSee('顧客ニーズの変化をサービスへ反映する');
    }

    public function test_viewer_can_read_but_cannot_record_observation(): void
    {
        [$viewer, $company] = $this->companyUser(OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->withSession($this->companySession($company))
            ->get(route('company-observations.index'))
            ->assertOk()
            ->assertSee('気付き・観察')
            ->assertDontSee('Observationとして記録');

        $this->actingAs($viewer)
            ->withSession($this->companySession($company))
            ->post(route('company-observations.store'), [
                'title' => '記録できないObservation',
                'body' => 'viewerは記録できない。',
                'source_type' => CompanyObservation::SOURCE_EMPLOYEE,
            ])
            ->assertForbidden();
    }

    public function test_observation_from_another_company_is_not_visible(): void
    {
        [$user, $company] = $this->companyUser(OrganizationUser::ROLE_OWNER);
        [$otherUser, $otherCompany] = $this->companyUser(OrganizationUser::ROLE_OWNER);
        $otherObservation = $this->observation($otherCompany, $otherUser);

        $this->actingAs($user)
            ->withSession($this->companySession($company))
            ->get(route('company-observations.show', $otherObservation))
            ->assertNotFound();
    }

    private function companyUser(string $role): array
    {
        $user = User::factory()->create();
        $company = Organization::create([
            'name' => 'Observation Company '.uniqid(),
            'slug' => 'observation-company-'.uniqid(),
        ]);
        $company->users()->attach($user->id, [
            'role' => $role,
            'joined_at' => now(),
        ]);

        return [$user, $company];
    }

    private function observation(Organization $company, User $user): CompanyObservation
    {
        return CompanyObservation::create([
            'organization_id' => $company->id,
            'title' => '問い合わせ内容が変化した',
            'body' => '同じ要望を含む問い合わせが3件あった。',
            'occurred_on' => now()->toDateString(),
            'observed_at' => now(),
            'observer_user_id' => $user->id,
            'source_type' => CompanyObservation::SOURCE_CUSTOMER,
            'status' => CompanyObservation::STATUS_RECORDED,
            'importance' => CompanyObservation::IMPORTANCE_UNREVIEWED,
        ]);
    }

    private function companySession(Organization $company): array
    {
        return [
            'access_mode' => 'workspace',
            'current_company_id' => $company->id,
        ];
    }
}
