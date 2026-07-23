<?php

namespace App\Http\Controllers;

use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyFinancialPeriodRevision;
use App\Models\OrganizationUser;
use App\Services\Company\AnnualProfitLossBulkParser;
use App\Services\Company\AnnualProfitLossCalculator;
use App\Services\Company\CompanyAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class CompanyFinanceController extends Controller
{
    public function index(Request $request, CompanyAccess $access): View
    {
        [$organization, $canManage] = $this->context($request, $access);

        return view('company-finance.overview', compact('organization', 'canManage'));
    }

    public function updateSettings(Request $request, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $validated = $request->validate(['fiscal_year_end_month' => ['required', 'integer', 'between:1,12']]);
        $organization->update($validated);

        return back()->with('status', '決算月を保存しました。');
    }

    public function profitLoss(Request $request, CompanyAccess $access): View
    {
        [$organization, $canManage] = $this->context($request, $access);
        $periods = CompanyFinancialPeriod::query()
            ->where('organization_id', $organization->id)
            ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
            ->orderByDesc('fiscal_year')->get();
        $chronological = $periods->sortBy('fiscal_year')->values();
        $latest = $periods->first();
        $previous = $periods->get(1);

        return view('company-finance.index', [
            'organization' => $organization, 'canManage' => $canManage,
            'periods' => $periods, 'latest' => $latest, 'previous' => $previous,
            'highestSales' => $periods->sortByDesc('net_sales')->first(),
            'profitablePeriodCount' => $periods->where('operating_profit', '>', 0)->count(),
            'salesGrowthRate' => $this->growthRate($latest?->net_sales, $previous?->net_sales),
            'latestNetIncomeRatio' => $latest && $latest->net_sales ? ($latest->net_income / $latest->net_sales) * 100 : null,
            'chartPeriods' => $chronological,
            'amountSeries' => [
                ['label' => '売上高', 'color' => '#165d6c', 'values' => $chronological->pluck('net_sales')->map(fn ($v) => (float) $v)->all()],
                ['label' => '売上総利益', 'color' => '#4c91a0', 'values' => $chronological->pluck('gross_profit')->map(fn ($v) => (float) $v)->all()],
                ['label' => '販管費', 'color' => '#be8a5a', 'values' => $chronological->pluck('selling_general_admin_expenses')->map(fn ($v) => (float) $v)->all()],
            ],
            'profitSeries' => [
                ['label' => '営業利益', 'color' => '#16705c', 'values' => $chronological->pluck('operating_profit')->map(fn ($v) => (float) $v)->all()],
                ['label' => '経常利益', 'color' => '#5d76aa', 'values' => $chronological->pluck('ordinary_profit')->map(fn ($v) => (float) $v)->all()],
                ['label' => '当期純利益', 'color' => '#9a5f7d', 'values' => $chronological->pluck('net_income')->map(fn ($v) => (float) $v)->all()],
            ],
            'marginSeries' => [
                ['label' => '粗利率', 'color' => '#165d6c', 'values' => $chronological->map(fn ($p) => (float) $p->gross_profit_ratio * 100)->all()],
                ['label' => '営業利益率', 'color' => '#16705c', 'values' => $chronological->map(fn ($p) => (float) $p->operating_profit_ratio * 100)->all()],
                ['label' => '最終利益率', 'color' => '#9a5f7d', 'values' => $chronological->map(fn ($p) => $p->net_sales ? ($p->net_income / $p->net_sales) * 100 : 0)->all()],
            ],
        ]);
    }

    public function create(Request $request, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        return view('company-finance.form', ['organization' => $organization, 'period' => null]);
    }

    public function edit(Request $request, CompanyFinancialPeriod $period, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($period, $organization->id);
        return view('company-finance.form', compact('organization', 'period'));
    }

    public function preview(Request $request, AnnualProfitLossCalculator $calculator, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        $period = $request->filled('period_id') ? CompanyFinancialPeriod::findOrFail($request->integer('period_id')) : null;
        if ($period) $this->owned($period, $organization->id);
        $input = $this->validatedInput($request, $organization->id, $period?->id);
        $calculated = $calculator->calculate($input);

        return view('company-finance.preview', compact('organization', 'period', 'input', 'calculated'));
    }

    public function store(Request $request, AnnualProfitLossCalculator $calculator, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $data = $calculator->calculate($this->validatedInput($request, $organization->id));
        $period = $this->savePeriod($organization->id, $request->user()->id, null, $data, CompanyFinancialPeriod::SOURCE_MANUAL);

        return redirect()->route('company-finance.pl.edit', $period)->with('status', '下書きを保存しました。');
    }

    public function update(Request $request, CompanyFinancialPeriod $period, AnnualProfitLossCalculator $calculator, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($period, $organization->id);
        $data = $calculator->calculate($this->validatedInput($request, $organization->id, $period->id));
        $this->savePeriod($organization->id, $request->user()->id, $period, $data, CompanyFinancialPeriod::SOURCE_MANUAL);

        return redirect()->route('company-finance.pl.edit', $period)->with('status', '変更を下書き保存しました。再確認後に確定してください。');
    }

    public function confirm(Request $request, CompanyFinancialPeriod $period, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $this->owned($period, $organization->id);
        $before = $period->toArray();
        $period->update(['record_status' => CompanyFinancialPeriod::RECORD_CONFIRMED, 'confirmed_at' => now(), 'confirmed_by' => $request->user()->id]);
        $this->revision($period, $request->user()->id, 'confirmed', $before);

        return back()->with('status', $period->period_number.'期を確定しました。');
    }

    public function bulk(Request $request, CompanyAccess $access): View
    {
        [$organization] = $this->manageContext($request, $access);
        return view('company-finance.bulk', compact('organization'));
    }

    public function bulkPreview(Request $request, AnnualProfitLossBulkParser $parser, CompanyAccess $access): View|RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $validated = $request->validate(['bulk_text' => ['required', 'string', 'max:100000']]);
        try {
            $rows = $parser->parse($validated['bulk_text']);
            $this->assertNoDuplicates($organization->id, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['bulk_text' => $e->getMessage()]);
        }

        return view('company-finance.bulk-preview', ['organization' => $organization, 'rows' => $rows, 'bulkText' => $validated['bulk_text']]);
    }

    public function bulkStore(Request $request, AnnualProfitLossBulkParser $parser, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $validated = $request->validate(['bulk_text' => ['required', 'string', 'max:100000']]);
        try {
            $rows = $parser->parse($validated['bulk_text']);
            $this->assertNoDuplicates($organization->id, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['bulk_text' => $e->getMessage()]);
        }
        DB::transaction(fn () => collect($rows)->each(fn ($row) => $this->savePeriod($organization->id, $request->user()->id, null, $row, CompanyFinancialPeriod::SOURCE_BULK)));

        return redirect()->route('company-finance.pl.index')->with('status', count($rows).'期分を下書き保存しました。');
    }

    public function placeholder(Request $request, string $section, CompanyAccess $access): View
    {
        [$organization] = $this->context($request, $access);
        $sections = ['bs' => 'B/S', 'plan' => '今年度計画と進捗', 'monthly' => '月次試算表', 'reconciliation' => '計画・実績の整合性と差異'];
        abort_unless(isset($sections[$section]), 404);
        return view('company-finance.placeholder', ['organization' => $organization, 'title' => $sections[$section]]);
    }

    private function validatedInput(Request $request, int $organizationId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'period_number' => ['required', 'integer', 'between:1,999'],
            'fiscal_year' => ['required', 'integer', 'between:1900,2200', Rule::unique('company_financial_periods')->where(fn ($q) => $q->where('organization_id', $organizationId)->where('status', CompanyFinancialPeriod::STATUS_ACTUAL))->ignore($ignoreId)],
            'net_sales' => ['required', 'integer', 'min:0'],
            'cost_of_sales' => ['required', 'integer', 'min:0'],
            'selling_general_admin_expenses' => ['required', 'integer', 'min:0'],
            'non_operating_income' => ['nullable', 'integer', 'min:0'],
            'non_operating_expenses' => ['nullable', 'integer', 'min:0'],
            'extraordinary_income' => ['nullable', 'integer', 'min:0'],
            'extraordinary_losses' => ['nullable', 'integer', 'min:0'],
            'income_taxes' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function savePeriod(int $organizationId, int $userId, ?CompanyFinancialPeriod $period, array $data, string $source): CompanyFinancialPeriod
    {
        return DB::transaction(function () use ($organizationId, $userId, $period, $data, $source) {
            $before = $period?->toArray();
            $values = array_merge($data, [
                'organization_id' => $organizationId, 'status' => CompanyFinancialPeriod::STATUS_ACTUAL,
                'record_status' => CompanyFinancialPeriod::RECORD_DRAFT, 'source_type' => $source,
                'confirmed_at' => null, 'confirmed_by' => null,
            ]);
            $period ? $period->update($values) : $period = CompanyFinancialPeriod::create($values);
            $this->revision($period, $userId, $before ? 'updated' : 'created', $before);
            return $period;
        });
    }

    private function revision(CompanyFinancialPeriod $period, int $userId, string $action, ?array $before): void
    {
        CompanyFinancialPeriodRevision::create([
            'company_financial_period_id' => $period->id, 'organization_id' => $period->organization_id,
            'changed_by' => $userId, 'action' => $action, 'before_data' => $before, 'after_data' => $period->fresh()->toArray(),
        ]);
    }

    private function assertNoDuplicates(int $organizationId, array $rows): void
    {
        $years = collect($rows)->pluck('fiscal_year');
        if ($years->duplicates()->isNotEmpty()) throw new InvalidArgumentException('同じ年度が複数行あります。');
        $existing = CompanyFinancialPeriod::where('organization_id', $organizationId)->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)->whereIn('fiscal_year', $years)->pluck('fiscal_year');
        if ($existing->isNotEmpty()) throw new InvalidArgumentException($existing->join('、').'年度は登録済みです。既存データの編集を使用してください。');
    }

    private function context(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL), 403);
        return [$organization, $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_PL)];
    }

    private function manageContext(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless($access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_PL), 403);
        return [$organization, true];
    }

    private function owned(CompanyFinancialPeriod $period, int $organizationId): void
    {
        abort_unless($period->organization_id === $organizationId, 404);
    }

    private function growthRate(int|float|null $current, int|float|null $previous): ?float
    {
        return $current === null || ! $previous ? null : (($current - $previous) / $previous) * 100;
    }
}
