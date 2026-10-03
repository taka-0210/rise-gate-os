<?php

namespace App\Http\Controllers;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyRelation;
use App\Models\AnnualManagementPolicyRevision;
use App\Models\OrganizationGroup;
use App\Models\OrganizationManagementPeriod;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\Task;
use App\Services\ActionExecution\ActionExecutionAccess;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyAccess;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyRelationService;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicySourceProvider;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodResolver;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnualManagementPolicyController extends Controller
{
    public function index(Request $request, AnnualManagementPolicyAccess $access, ManagementPeriodResolver $resolver): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeMembership($request->user(), $organization);
        $periods = OrganizationManagementPeriod::query()->where('organization_id', $organization->id)
            ->with(['annualPolicy.currentApprovedRevision'])->orderByDesc('starts_on')->get();
        $current = $resolver->current($organization);

        return view('annual-management-policy.index', [
            'organization' => $organization,
            'periods' => $periods,
            'currentPeriodId' => $current?->id,
            'canManage' => $access->canManage($request->user(), $organization),
            'access' => $access,
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function storePeriod(Request $request, ManagementPeriodWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d'],
        ]);
        $writer->register(
            $request->user(), $request->attributes->get('currentCompany'), $validated['name'],
            $validated['starts_on'], $validated['ends_on'], $validated['request_id'],
        );
        return back()->with('status', '会社の期間を登録しました。');
    }

    public function updatePeriod(Request $request, OrganizationManagementPeriod $period, ManagementPeriodWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'], 'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'], 'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $writer->correct(
            $request->user(), $request->attributes->get('currentCompany'), $period, $validated['name'],
            $validated['starts_on'], $validated['ends_on'], (int) $validated['expected_version'],
            $validated['change_reason'] ?? null, $validated['request_id'],
        );
        return back()->with('status', '会社の期間を訂正しました。正式版に記録された期間は変わりません。');
    }

    public function initialize(Request $request, OrganizationManagementPeriod $period, AnnualManagementPolicyPermissionManager $manager): RedirectResponse
    {
        $validated = $request->validate(['request_id' => ['required', 'uuid']]);
        $policy = $manager->initialize(
            $request->user(), $request->attributes->get('currentCompany'), $period, $validated['request_id'],
        );
        return redirect()->route('annual-management-policy.permissions', $policy)
            ->with('status', '年度方針の枠を作成しました。本文を扱う担当者を明示してください。');
    }

    public function show(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyAccess $access): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $canApproved = $access->canViewApproved($request->user(), $annualPolicy);
        $canDraft = $access->canViewDraft($request->user(), $annualPolicy);
        abort_unless($canApproved || $canDraft || $access->canManage($request->user(), $annualPolicy->organization), 403);
        $annualPolicy->load(['period', 'currentApprovedRevision', 'revisions']);

        return view('annual-management-policy.show', [
            'organization' => $annualPolicy->organization,
            'annualPolicy' => $annualPolicy,
            'approved' => $canApproved ? $annualPolicy->currentApprovedRevision?->snapshot : null,
            'canApproved' => $canApproved,
            'canDraft' => $canDraft,
            'canEdit' => $access->canEdit($request->user(), $annualPolicy),
            'canApprove' => $access->canApprove($request->user(), $annualPolicy),
            'canManage' => $access->canManage($request->user(), $annualPolicy->organization),
        ]);
    }

    public function edit(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyAccess $access): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $access->authorizeEdit($request->user(), $annualPolicy);
        $annualPolicy->load(['period', 'themes.priorities', 'departments.group', 'departments.statements']);
        return view('annual-management-policy.edit', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy,
            'groups' => OrganizationGroup::query()->where('organization_id', $annualPolicy->organization_id)
                ->whereNull('archived_at')->orderBy('name')->get(),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function update(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyWriter $writer): RedirectResponse
    {
        $this->assertOrganization($request, $annualPolicy);
        $validated = $request->validate($this->draftRules());
        $writer->saveDraft($request->user(), $annualPolicy, $validated, (int) $validated['expected_draft_version'], $validated['request_id']);
        return redirect()->route('annual-management-policy.show', $annualPolicy)
            ->with('status', '作成中の案を保存しました。まだ正式方針ではありません。');
    }

    public function approval(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyWriter $writer): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $preview = $writer->preview($request->user(), $annualPolicy);
        app(AnnualManagementPolicyAccess::class)->authorizeApprove($request->user(), $annualPolicy);
        $annualPolicy->load(['period', 'currentApprovedRevision']);
        return view('annual-management-policy.approval', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy,
            'preview' => $preview, 'requestId' => (string) Str::uuid(),
        ]);
    }

    public function approve(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyWriter $writer): RedirectResponse
    {
        $this->assertOrganization($request, $annualPolicy);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'], 'expected_draft_version' => ['required', 'integer', 'min:0'],
            'expected_base_revision_no' => ['nullable', 'integer', 'min:1'],
            'expected_relation_version' => ['required', 'integer', 'min:0'],
            'expected_snapshot_hash' => ['required', 'string', 'size:64'],
            'period_difference_confirmed' => ['nullable', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:2000'], 'confirm' => ['accepted'],
        ]);
        $writer->approve(
            $request->user(), $annualPolicy, (int) $validated['expected_draft_version'],
            isset($validated['expected_base_revision_no']) ? (int) $validated['expected_base_revision_no'] : null,
            (int) $validated['expected_relation_version'], $validated['expected_snapshot_hash'],
            (bool) ($validated['period_difference_confirmed'] ?? false), $validated['change_reason'] ?? null,
            $validated['request_id'],
        );
        return redirect()->route('annual-management-policy.show', $annualPolicy)
            ->with('status', '年度経営方針を正式承認しました。');
    }

    public function history(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyAccess $access): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $access->authorizeApprovedView($request->user(), $annualPolicy);
        return view('annual-management-policy.history', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy->load('period'),
            'revisions' => $annualPolicy->revisions()->with('approver')->paginate(20),
        ]);
    }

    public function revision(Request $request, AnnualManagementPolicy $annualPolicy, int $revision, AnnualManagementPolicyAccess $access): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $access->authorizeApprovedView($request->user(), $annualPolicy);
        $record = AnnualManagementPolicyRevision::query()->where('annual_management_policy_id', $annualPolicy->id)
            ->where('revision_no', $revision)->with('approver')->firstOrFail();
        return view('annual-management-policy.revision', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy->load('period'), 'revision' => $record,
        ]);
    }

    public function permissions(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyAccess $access): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $access->authorizeManage($request->user(), $annualPolicy->organization);
        return view('annual-management-policy.permissions', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy->load(['period', 'grants']),
            'memberships' => OrganizationUser::query()->where('organization_id', $annualPolicy->organization_id)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)->with('user')->orderBy('id')->get(),
            'grants' => $annualPolicy->grants->keyBy('organization_user_id'), 'requestId' => (string) Str::uuid(),
        ]);
    }

    public function updatePermissions(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyPermissionManager $manager): RedirectResponse
    {
        $this->assertOrganization($request, $annualPolicy);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'approved_view_scope' => ['required', Rule::in(AnnualManagementPolicy::VIEW_SCOPES)],
            'grants' => ['nullable', 'array'], 'grants.*' => ['array'],
            'grants.*.can_view_approved' => ['nullable', 'boolean'], 'grants.*.can_view_draft' => ['nullable', 'boolean'],
            'grants.*.can_edit' => ['nullable', 'boolean'], 'grants.*.can_approve' => ['nullable', 'boolean'],
        ]);
        $manager->update(
            $request->user(), $annualPolicy->organization, $annualPolicy,
            $validated['approved_view_scope'], $validated['grants'] ?? [], $validated['request_id'],
        );
        return back()->with('status', '年度方針の共有・担当設定を更新しました。');
    }

    public function relations(
        Request $request,
        AnnualManagementPolicy $annualPolicy,
        AnnualManagementPolicyAccess $access,
        ProjectExecutionAccess $projects,
        ActionExecutionAccess $actions,
    ): View {
        $this->assertOrganization($request, $annualPolicy);
        $access->authorizeEdit($request->user(), $annualPolicy);
        $annualPolicy->load(['themes.priorities', 'departments.group', 'relations.versions']);
        $projectOptions = Project::query()->where('organization_id', $annualPolicy->organization_id)->get()
            ->filter(fn (Project $project) => $projects->canRead($request->user(), $project));
        $actionOptions = Task::query()->with('project')->where('organization_id', $annualPolicy->organization_id)->get()
            ->filter(fn (Task $task) => $actions->canRead($request->user(), $task));
        return view('annual-management-policy.relations', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy,
            'projects' => $projectOptions, 'actions' => $actionOptions,
            'groups' => OrganizationGroup::query()->where('organization_id', $annualPolicy->organization_id)->whereNull('archived_at')->get(),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function storeRelation(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyRelationService $relations): RedirectResponse
    {
        $this->assertOrganization($request, $annualPolicy);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'], 'expected_relation_version' => ['required', 'integer', 'min:0'],
            'source_type' => ['required', Rule::in(AnnualManagementPolicyRelationService::SOURCE_TYPES)],
            'source_public_id' => ['required', 'string', 'size:26'],
            'target_type' => ['required', Rule::in(AnnualManagementPolicyRelationService::TARGET_TYPES)],
            'target_public_id' => ['required', 'string', 'size:26'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $relations->confirm(
            $request->user(), $annualPolicy, $validated['source_type'], $validated['source_public_id'],
            $validated['target_type'], $validated['target_public_id'], (int) $validated['expected_relation_version'],
            $validated['reason'] ?? null, $validated['request_id'],
        );
        return back()->with('status', '関連する取り組みを確認済みとして追加しました。');
    }

    public function withdrawRelation(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicyRelation $relation, AnnualManagementPolicyRelationService $relations): RedirectResponse
    {
        $this->assertOrganization($request, $annualPolicy);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'], 'expected_relation_version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $relations->withdraw(
            $request->user(), $annualPolicy, $relation, (int) $validated['expected_relation_version'],
            $validated['reason'] ?? null, $validated['request_id'],
        );
        return back()->with('status', '関連付けを撤回しました。過去の記録は保持されます。');
    }

    public function sourceEvidence(Request $request, AnnualManagementPolicy $annualPolicy, AnnualManagementPolicySourceProvider $sources): View
    {
        $this->assertOrganization($request, $annualPolicy);
        $revision = (int) $request->integer('revision', $annualPolicy->currentApprovedRevision?->revision_no ?? 0);
        $export = $sources->export($request->user(), $annualPolicy->organization, AnnualManagementPolicySourceProvider::MODE_HISTORICAL, [
            'annual_public_id' => $annualPolicy->public_id, 'revision_no' => $revision,
        ]);
        return view('annual-management-policy.source-evidence', [
            'organization' => $annualPolicy->organization, 'annualPolicy' => $annualPolicy->load('period'), 'export' => $export,
        ]);
    }

    private function assertOrganization(Request $request, AnnualManagementPolicy $policy): void
    {
        abort_unless($policy->organization_id === $request->attributes->get('currentCompany')?->id, 404);
    }

    private function draftRules(): array
    {
        return [
            'request_id' => ['required', 'uuid'], 'expected_draft_version' => ['required', 'integer', 'min:0'],
            'period_name' => ['nullable', 'string', 'max:120'], 'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'], 'purpose' => ['nullable', 'string', 'max:50000'],
            'background' => ['nullable', 'string', 'max:50000'], 'policy' => ['nullable', 'string', 'max:50000'],
            'themes' => ['nullable', 'array', 'max:100'], 'themes.*.public_id' => ['nullable', 'string', 'size:26'],
            'themes.*.statement' => ['nullable', 'string', 'max:50000'], 'themes.*.explanation' => ['nullable', 'string', 'max:50000'],
            'themes.*.priorities' => ['nullable', 'array', 'max:100'],
            'themes.*.priorities.*.public_id' => ['nullable', 'string', 'size:26'],
            'themes.*.priorities.*.statement' => ['nullable', 'string', 'max:50000'],
            'themes.*.priorities.*.explanation' => ['nullable', 'string', 'max:50000'],
            'departments' => ['nullable', 'array', 'max:100'], 'departments.*.public_id' => ['nullable', 'string', 'size:26'],
            'departments.*.group_public_id' => ['required', 'string', 'size:26'],
            'departments.*.introduction' => ['nullable', 'string', 'max:50000'],
            'departments.*.statements' => ['nullable', 'array', 'max:100'],
            'departments.*.statements.*.public_id' => ['nullable', 'string', 'size:26'],
            'departments.*.statements.*.statement' => ['nullable', 'string', 'max:50000'],
            'departments.*.statements.*.explanation' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
