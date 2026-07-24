<?php

namespace Tests\Feature;

use App\Models\CompanyLoan;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyLoanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_save_confirm_and_view_loan_dashboard(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.preview'), $input)
            ->assertOk()->assertSee('29,250,000円')->assertSee('2026-05-31');

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.store'), $input)->assertRedirect();

        $loan = CompanyLoan::firstOrFail();
        $this->assertSame(CompanyLoan::RECORD_DRAFT, $loan->record_status);
        $this->assertCount(1, $loan->revisions);
        $this->assertDatabaseHas('company_loan_balance_snapshots', [
            'company_loan_id' => $loan->id, 'balance' => 29_250_000,
        ]);
        $this->assertTrue($loan->balanceSnapshots()->whereDate('balance_as_of', '2026-05-31')->exists());

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.confirm', $loan))->assertRedirect();
        $this->assertSame(CompanyLoan::RECORD_CONFIRMED, $loan->fresh()->record_status);

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.index'))->assertOk()
            ->assertSee('借入・資金計画')->assertSee('29,250,000')
            ->assertSee('250,000')->assertSee('加重平均金利')
            ->assertSee('5年間の借入残高見通し', false)
            ->assertSee('月別残高推移表');

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.schedule', ['start' => '2026-05', 'end' => '2026-07']))
            ->assertOk()
            ->assertSee('借入残高推移表')
            ->assertSee('29,250,000')
            ->assertSee('29,000,000')
            ->assertSee('28,750,000')
            ->assertSee('●');

        $this->actingAs($owner)->withSession($session)
            ->get(route('company.home'))->assertOk()->assertSee('借入残高 29,250,000円');
    }

    public function test_bulk_paste_saves_multiple_loans_as_drafts(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $text = "金融機関\t管理番号\t用途\t実行年月\t期間\t当初借入額\t現在残高\t月額元金\t年利\t金利区分\t直近利息\t完済年月\t保証・区分\t返済日\t残高基準日\t状態\n".
            "A銀行\t1\t運転資金\t2025-05\t5年\t30000000\t23500000\t500000\t1.775\tvariable\t35013\t2030-04\t保証協付\t25\t2026-05-31\tactive\n".
            "B銀行\t2\t運転資金\t2025-09\t7年\t30000000\t26787000\t357000\t1.45\tfixed\t32775\t2032-08\t保証協付\t27\t2026-05-31\tactive";

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.bulk.preview'), ['bulk_text' => $text])
            ->assertOk()->assertSee('2件を確認');
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.bulk.store'), ['bulk_text' => $text])
            ->assertRedirect(route('company-loans.index'));

        $this->assertDatabaseCount('company_loans', 2);
        $this->assertDatabaseHas('company_loans', [
            'organization_id' => $organization->id, 'financial_institution' => 'A銀行',
            'record_status' => CompanyLoan::RECORD_DRAFT, 'source_type' => CompanyLoan::SOURCE_BULK,
        ]);
        $loans = CompanyLoan::orderBy('management_number')->get();
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.confirm-drafts'), [
                'scope' => 'selected', 'ids' => [$loans->first()->id],
            ])->assertRedirect();
        $this->assertSame(CompanyLoan::RECORD_CONFIRMED, $loans->first()->fresh()->record_status);
        $this->assertSame(CompanyLoan::RECORD_DRAFT, $loans->last()->fresh()->record_status);

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.confirm-drafts'), ['scope' => 'all'])
            ->assertRedirect();
        $this->assertSame(CompanyLoan::RECORD_CONFIRMED, $loans->last()->fresh()->record_status);
        $loans->last()->update([
            'loan_status' => CompanyLoan::STATUS_COMPLETED,
            'completed_on' => '2026-06-30',
        ]);
        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.index'))
            ->assertOk()->assertDontSee('<input type="checkbox" data-check-all', false);
        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.schedule', [
                'start' => '2026-05', 'end' => '2026-06',
                'sort' => 'institution', 'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['No.1', 'No.2'])
            ->assertSee('loan-completed')
            ->assertSee('完済 2026.06')
            ->assertSee('金融機関 ▼');

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.bulk'))
            ->assertOk()->assertSee('1</b><span>金融機関', false)->assertSee('16</b><span>状態', false);
    }

    public function test_member_needs_explicit_debt_permission(): void
    {
        [$member, $organization, $session] = $this->companyUser('member');
        $this->actingAs($member)->withSession($session)->get(route('company-loans.index'))->assertForbidden();

        OrganizationUser::where('organization_id', $organization->id)->where('user_id', $member->id)
            ->update(['permissions' => [OrganizationUser::PERMISSION_FINANCE_VIEW_DEBT]]);

        $this->actingAs($member)->withSession($session)->get(route('company-loans.index'))->assertOk();
        $this->actingAs($member)->withSession($session)->get(route('company-loans.create'))->assertForbidden();
    }

    public function test_hold_projection_keeps_the_balance_after_the_maturity_month(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();
        $input['management_number'] = '2';
        $input['original_amount'] = 50_000_000;
        $input['current_balance'] = 50_000_000;
        $input['monthly_principal_payment'] = 0;
        $input['balance_projection_mode'] = CompanyLoan::PROJECTION_HOLD;
        $input['maturity_on'] = '2026-06-01';

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.store'), $input)
            ->assertRedirect();

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.schedule', ['start' => '2026-05', 'end' => '2026-08']))
            ->assertOk()
            ->assertSee('据置')
            ->assertSeeInOrder(['2026年', '50,000,000', '50,000,000', '50,000,000', '50,000,000']);
    }

    public function test_completed_loan_requires_an_actual_completion_date(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();
        $input['loan_status'] = CompanyLoan::STATUS_COMPLETED;
        $input['completed_on'] = null;

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.preview'), $input)
            ->assertSessionHasErrors('completed_on');

        $input['completed_on'] = '2026-06-30';
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.preview'), $input)
            ->assertOk()->assertSee('2026-06-30');
    }

    public function test_completed_date_can_be_saved_from_the_preview_with_post(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.store'), $input)
            ->assertRedirect();
        $loan = CompanyLoan::firstOrFail();

        $input['loan_id'] = $loan->id;
        $input['loan_status'] = CompanyLoan::STATUS_COMPLETED;
        $input['completed_on'] = '2026-06-30';
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.preview'), $input)
            ->assertOk()
            ->assertSee(route('company-loans.save', $loan), false)
            ->assertDontSee('name="_method"', false);

        unset($input['loan_id']);
        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.save', $loan), $input)
            ->assertRedirect(route('company-loans.edit', $loan));
        $this->assertSame(CompanyLoan::STATUS_COMPLETED, $loan->fresh()->loan_status);
        $this->assertSame('2026-06-30', $loan->fresh()->completed_on->toDateString());
    }

    public function test_completed_loan_reconstructs_monthly_balances_before_completion(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();
        $input['management_number'] = '4';
        $input['executed_on'] = '2026-01-01';
        $input['original_amount'] = 1_000_000;
        $input['current_balance'] = 0;
        $input['monthly_principal_payment'] = 0;
        $input['balance_as_of'] = '2026-07-31';
        $input['loan_status'] = CompanyLoan::STATUS_COMPLETED;
        $input['completed_on'] = '2026-06-30';

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.store'), $input)
            ->assertRedirect();

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.schedule', ['start' => '2026-03', 'end' => '2026-06']))
            ->assertOk()
            ->assertSee('200,000')
            ->assertSee('自動計算')
            ->assertSeeInOrder(['600,000', '400,000', '200,000', '0']);
    }

    public function test_completed_loan_subtracts_registered_monthly_payment_until_completion_then_zeros_next_month(): void
    {
        [$owner, $organization, $session] = $this->companyUser('owner');
        $input = $this->loanInput();
        $input['management_number'] = '4';
        $input['executed_on'] = '2026-01-01';
        $input['original_amount'] = 1_000_000;
        $input['current_balance'] = 0;
        $input['monthly_principal_payment'] = 100_000;
        $input['balance_as_of'] = '2026-07-31';
        $input['loan_status'] = CompanyLoan::STATUS_COMPLETED;
        $input['completed_on'] = '2026-06-30';

        $this->actingAs($owner)->withSession($session)
            ->post(route('company-loans.store'), $input)
            ->assertRedirect();

        $response = $this->actingAs($owner)->withSession($session)
            ->get(route('company-loans.schedule', ['start' => '2026-03', 'end' => '2026-07']))
            ->assertOk()
            ->assertSeeInOrder(['800,000', '700,000', '600,000', '500,000', '0'])
            ->assertSee('<td class="total-column"><strong>0</strong></td>', false);

        $this->assertSame(2, substr_count($response->getContent(), '<th class="total-column">0</th>'));
    }

    private function loanInput(): array
    {
        return [
            'financial_institution' => '姫路信用金庫', 'management_number' => '16',
            'purpose' => '運転資金', 'executed_on' => '2026-03-01', 'term_label' => '10年',
            'original_amount' => 30_000_000, 'current_balance' => 29_250_000,
            'monthly_principal_payment' => 250_000, 'annual_interest_rate' => 1.996,
            'balance_projection_mode' => CompanyLoan::PROJECTION_AMORTIZING,
            'interest_type' => 'variable', 'recent_interest_amount' => 0,
            'maturity_on' => '2036-03-01', 'guarantee_type' => '保証協付',
            'repayment_day' => '25', 'balance_as_of' => '2026-05-31',
            'loan_status' => 'active', 'notes' => null,
            'completed_on' => null,
        ];
    }

    private function companyUser(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Company', 'slug' => 'test-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id, 'owner_user_id' => $role === 'owner' ? $user->id : null,
            'name' => 'Main', 'slug' => 'main-'.uniqid(), 'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        return [$user, $organization, ['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id]];
    }
}
