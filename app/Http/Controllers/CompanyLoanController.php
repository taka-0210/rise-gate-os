<?php

namespace App\Http\Controllers;

use App\Models\CompanyLoan;
use App\Models\CompanyLoanBalanceSnapshot;
use App\Models\CompanyLoanRevision;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use App\Services\Company\CompanyLoanBulkParser;
use App\Services\Company\LoanProjectionService;
use App\Services\Company\LoanScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class CompanyLoanController extends Controller
{
    public function schedule(Request $request, CompanyAccess $access, LoanScheduleService $schedule): View
    {
        [$organization] = $this->viewContext($request, $access);
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m'],
            'end' => ['nullable', 'date_format:Y-m'],
        ]);
        $start = isset($validated['start'])
            ? CarbonImmutable::createFromFormat('Y-m', $validated['start'])->startOfMonth()
            : CarbonImmutable::now()->subYears(4)->startOfYear();
        $end = isset($validated['end'])
            ? CarbonImmutable::createFromFormat('Y-m', $validated['end'])->startOfMonth()
            : CarbonImmutable::now()->addYears(5)->endOfYear()->startOfMonth();
        abort_if($end->lessThan($start), 422, '終了年月は開始年月以降にしてください。');
        abort_if($start->diffInMonths($end) > 180, 422, '表示期間は15年以内にしてください。');

        $loans = CompanyLoan::query()
            ->where('organization_id', $organization->id)
            ->with('balanceSnapshots')
            ->orderBy('financial_institution')
            ->orderBy('management_number')
            ->get();

        return view('company-loans.schedule', [
            'organization' => $organization,
            'loans' => $loans,
            'rows' => $schedule->build($loans, $start, $end),
            'start' => $start,
            'end' => $end,
        ]);
    }

    public function index(Request $request, CompanyAccess $access, LoanProjectionService $projection): View
    {
        [$organization, $canManage] = $this->viewContext($request, $access);
        $loans = CompanyLoan::where('organization_id', $organization->id)
            ->orderByRaw("CASE loan_status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 ELSE 2 END")
            ->orderBy('financial_institution')->orderBy('management_number')->get();
        $active = $loans->where('loan_status', CompanyLoan::STATUS_ACTIVE);
        $totalBalance = $active->sum('current_balance');
        $weightedRate = $totalBalance > 0
            ? $active->sum(fn ($loan) => $loan->current_balance * (float) $loan->annual_interest_rate) / $totalBalance
            : null;

        return view('company-loans.index', [
            'organization' => $organization, 'loans' => $loans, 'activeLoans' => $active,
            'canManage' => $canManage, 'totalBalance' => $totalBalance,
            'monthlyPrincipal' => $active->sum('monthly_principal_payment'),
            'recentInterest' => $active->sum('recent_interest_amount'),
            'weightedRate' => $weightedRate,
            'byInstitution' => $active->groupBy('financial_institution')->map(fn ($items) => [
                'count' => $items->count(), 'balance' => $items->sum('current_balance'),
                'monthly' => $items->sum('monthly_principal_payment'),
            ])->sortByDesc('balance'),
            'projection' => $projection->project($active->values()),
        ]);
    }

    public function create(Request $request, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        return view('company-loans.form', ['organization' => $organization, 'loan' => null]);
    }

    public function edit(Request $request, CompanyLoan $loan, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($loan, $organization->id);
        return view('company-loans.form', compact('organization', 'loan'));
    }

    public function preview(Request $request, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        $loan = $request->filled('loan_id') ? CompanyLoan::findOrFail($request->integer('loan_id')) : null;
        if ($loan) $this->owned($loan, $organization->id);
        $input = $this->validated($request, $organization->id, $loan?->id);
        return view('company-loans.preview', compact('organization', 'loan', 'input'));
    }

    public function store(Request $request, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $loan = $this->saveLoan($organization->id, $request->user()->id, null, $this->validated($request, $organization->id), CompanyLoan::SOURCE_MANUAL);
        return redirect()->route('company-loans.edit', $loan)->with('status', '借入契約を下書き保存しました。');
    }

    public function update(Request $request, CompanyLoan $loan, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($loan, $organization->id);
        $this->saveLoan($organization->id, $request->user()->id, $loan, $this->validated($request, $organization->id, $loan->id), CompanyLoan::SOURCE_MANUAL);
        return back()->with('status', '変更を下書き保存しました。再確認後に確定してください。');
    }

    public function confirm(Request $request, CompanyLoan $loan, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($loan, $organization->id);
        $before = $loan->toArray();
        $loan->update(['record_status' => CompanyLoan::RECORD_CONFIRMED, 'confirmed_at' => now(), 'confirmed_by' => $request->user()->id]);
        $this->revision($loan, $request->user()->id, 'confirmed', $before);
        return back()->with('status', '借入契約を確定しました。');
    }

    public function confirmDrafts(Request $request, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['selected', 'all'])],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);
        $query = CompanyLoan::query()
            ->where('organization_id', $organization->id)
            ->where('record_status', CompanyLoan::RECORD_DRAFT);
        if ($validated['scope'] === 'selected') {
            $ids = collect($validated['ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            if ($ids->isEmpty()) {
                return back()->withErrors(['ids' => '確定する借入を選択してください。']);
            }
            $query->whereIn('id', $ids);
        }
        $loans = $query->get();
        DB::transaction(function () use ($loans, $request): void {
            foreach ($loans as $loan) {
                $before = $loan->toArray();
                $loan->update([
                    'record_status' => CompanyLoan::RECORD_CONFIRMED,
                    'confirmed_at' => now(),
                    'confirmed_by' => $request->user()->id,
                ]);
                $this->revision($loan, $request->user()->id, 'confirmed', $before);
            }
        });

        return back()->with('status', $loans->count().'件の借入を確定しました。');
    }

    public function bulk(Request $request, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        return view('company-loans.bulk', compact('organization'));
    }

    public function bulkPreview(Request $request, CompanyLoanBulkParser $parser, CompanyAccess $access): View|RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $text = $request->validate(['bulk_text' => ['required', 'string', 'max:200000']])['bulk_text'];
        try {
            $rows = $parser->parse($text);
            $this->assertNoDuplicates($organization->id, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['bulk_text' => $e->getMessage()]);
        }
        return view('company-loans.bulk-preview', ['organization' => $organization, 'rows' => $rows, 'bulkText' => $text]);
    }

    public function bulkStore(Request $request, CompanyLoanBulkParser $parser, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $text = $request->validate(['bulk_text' => ['required', 'string', 'max:200000']])['bulk_text'];
        try {
            $rows = $parser->parse($text);
            $this->assertNoDuplicates($organization->id, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['bulk_text' => $e->getMessage()]);
        }
        try {
            DB::transaction(fn () => collect($rows)->each(fn ($row) => $this->saveLoan($organization->id, $request->user()->id, null, $row, CompanyLoan::SOURCE_BULK)));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors([
                'bulk_text' => '保存処理に失敗しました。入力内容を確認して、もう一度お試しください。',
            ]);
        }
        return redirect()->route('company-loans.index')->with('status', count($rows).'件を下書き保存しました。');
    }

    private function validated(Request $request, int $organizationId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'financial_institution' => ['required', 'string', 'max:255'],
            'management_number' => ['required', 'string', 'max:50', Rule::unique('company_loans')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('financial_institution', $request->input('financial_institution')))->ignore($ignoreId)],
            'purpose' => ['nullable', 'string', 'max:255'], 'executed_on' => ['nullable', 'date'],
            'term_label' => ['nullable', 'string', 'max:50'], 'original_amount' => ['required', 'integer', 'min:0'],
            'current_balance' => ['required', 'integer', 'min:0'], 'monthly_principal_payment' => ['required', 'integer', 'min:0'],
            'balance_projection_mode' => ['required', Rule::in([
                CompanyLoan::PROJECTION_AMORTIZING, CompanyLoan::PROJECTION_HOLD,
                CompanyLoan::PROJECTION_BULLET, CompanyLoan::PROJECTION_REVOLVING,
            ])],
            'annual_interest_rate' => ['nullable', 'numeric', 'between:0,100'], 'interest_type' => ['nullable', Rule::in(['fixed', 'variable', 'other'])],
            'recent_interest_amount' => ['nullable', 'integer', 'min:0'], 'maturity_on' => ['nullable', 'date'],
            'guarantee_type' => ['nullable', 'string', 'max:255'], 'repayment_day' => ['nullable', 'string', 'max:20'],
            'balance_as_of' => ['required', 'date'], 'loan_status' => ['required', Rule::in(['active', 'completed', 'planned'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function saveLoan(int $organizationId, int $userId, ?CompanyLoan $loan, array $data, string $source): CompanyLoan
    {
        return DB::transaction(function () use ($organizationId, $userId, $loan, $data, $source) {
            $before = $loan?->toArray();
            $data['balance_projection_mode'] ??= ((int) ($data['monthly_principal_payment'] ?? 0) === 0
                ? CompanyLoan::PROJECTION_HOLD
                : CompanyLoan::PROJECTION_AMORTIZING);
            $values = array_merge($data, [
                'organization_id' => $organizationId, 'record_status' => CompanyLoan::RECORD_DRAFT,
                'source_type' => $source, 'confirmed_at' => null, 'confirmed_by' => null,
            ]);
            $loan ? $loan->update($values) : $loan = CompanyLoan::create($values);
            if ($loan->balance_as_of) {
                CompanyLoanBalanceSnapshot::updateOrCreate(
                    ['company_loan_id' => $loan->id, 'balance_as_of' => $loan->balance_as_of->toDateString()],
                    [
                        'organization_id' => $organizationId, 'balance' => $loan->current_balance,
                        'monthly_principal_payment' => $loan->monthly_principal_payment,
                        'interest_amount' => $loan->recent_interest_amount, 'recorded_by' => $userId,
                    ]
                );
            }
            $this->revision($loan, $userId, $before ? 'updated' : 'created', $before);
            return $loan;
        });
    }

    private function revision(CompanyLoan $loan, int $userId, string $action, ?array $before): void
    {
        CompanyLoanRevision::create([
            'company_loan_id' => $loan->id, 'organization_id' => $loan->organization_id,
            'changed_by' => $userId, 'action' => $action, 'before_data' => $before, 'after_data' => $loan->fresh()->toArray(),
        ]);
    }

    private function assertNoDuplicates(int $organizationId, array $rows): void
    {
        $keys = collect($rows)->map(fn ($row) => $row['financial_institution'].'|'.$row['management_number']);
        if ($keys->duplicates()->isNotEmpty()) throw new InvalidArgumentException('同じ金融機関・管理番号が複数行あります。');
        foreach ($rows as $row) {
            if (CompanyLoan::where('organization_id', $organizationId)->where('financial_institution', $row['financial_institution'])->where('management_number', $row['management_number'])->exists()) {
                throw new InvalidArgumentException($row['financial_institution'].' '.$row['management_number'].'は登録済みです。');
            }
        }
    }

    private function viewContext(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_DEBT), 403);
        return [$organization, $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_DEBT)];
    }

    private function manageContext(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_DEBT), 403);
        return [$organization, true];
    }

    private function owned(CompanyLoan $loan, int $organizationId): void
    {
        abort_unless($loan->organization_id === $organizationId, 404);
    }
}
