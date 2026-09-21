<?php

namespace App\Http\Controllers;

use App\Models\BusinessDomain;
use App\Models\BusinessDomainItem;
use App\Models\BusinessDomainItemAttribute;
use App\Models\BusinessDomainRevision;
use App\Models\OrganizationUser;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\BusinessDomain\BusinessDomainGrantManager;
use App\Services\BusinessDomain\BusinessDomainQuery;
use App\Services\BusinessDomain\BusinessDomainWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BusinessDomainController extends Controller
{
    public function index(
        Request $request,
        BusinessDomainAccess $access,
        BusinessDomainQuery $query,
    ): View {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $organization = $request->attributes->get('currentCompany');

        return view('business-domains.index', [
            'organization' => $organization,
            'domains' => $query->paginate(
                $request->user(),
                $organization,
                $validated['status'] ?? BusinessDomain::STATUS_ACTIVE,
                isset($validated['q']) ? trim($validated['q']) : null,
                (int) ($validated['per_page'] ?? 20),
            ),
            'status' => $validated['status'] ?? BusinessDomain::STATUS_ACTIVE,
            'search' => $validated['q'] ?? '',
            'statusCounts' => $query->statusCounts($request->user(), $organization),
            'canEdit' => $access->canEdit($request->user(), $organization),
            'isOwner' => $this->isOwner($request, $access),
        ]);
    }

    public function create(Request $request, BusinessDomainAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeEdit($request->user(), $organization);

        return view('business-domains.create', [
            'organization' => $organization,
            'domain' => new BusinessDomain,
            'requestId' => (string) Str::uuid(),
            'itemKinds' => BusinessDomainItem::KINDS,
            'attributeAxes' => BusinessDomainItemAttribute::AXES,
            'directions' => BusinessDomain::DIRECTIONS,
        ]);
    }

    public function manage(
        Request $request,
        BusinessDomainAccess $access,
        BusinessDomainQuery $query,
    ): View {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeEdit($request->user(), $organization);

        return view('business-domains.manage', [
            'organization' => $organization,
            'domains' => $query->paginate(
                $request->user(),
                $organization,
                $validated['status'] ?? BusinessDomain::STATUS_ACTIVE,
                isset($validated['q']) ? trim($validated['q']) : null,
                (int) ($validated['per_page'] ?? 20),
            ),
            'status' => $validated['status'] ?? BusinessDomain::STATUS_ACTIVE,
            'search' => $validated['q'] ?? '',
            'statusCounts' => $query->statusCounts($request->user(), $organization),
            'isOwner' => $this->isOwner($request, $access),
        ]);
    }

    public function store(Request $request, BusinessDomainWriter $writer): RedirectResponse
    {
        $validated = $request->validate($this->domainRules(false));
        $domain = $writer->create(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $this->domainInput($validated),
            $validated['request_id'],
        );

        return redirect()->route('business-domains.show', $domain)
            ->with('status', '事業領域を登録しました。');
    }

    public function show(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainAccess $access,
    ): View {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeView($request->user(), $organization);
        $this->assertOrganization($businessDomain, $organization->id);
        $businessDomain->load([
            'items' => fn ($query) => $query->where('status', BusinessDomainItem::STATUS_ACTIVE),
            'items.attributes' => fn ($query) => $query->where('status', BusinessDomainItemAttribute::STATUS_ACTIVE),
        ]);

        return view('business-domains.show', [
            'organization' => $organization,
            'domain' => $businessDomain,
            'canEdit' => $access->canEdit($request->user(), $organization),
        ]);
    }

    public function manageShow(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainAccess $access,
    ): View {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeEdit($request->user(), $organization);
        $access->authorizeHistory($request->user(), $organization);
        $this->assertOrganization($businessDomain, $organization->id);

        return view('business-domains.manage-show', [
            'organization' => $organization,
            'domain' => $businessDomain,
            'revisions' => $businessDomain->revisions()->with('actor')->paginate(20),
            'archiveRequestId' => (string) Str::uuid(),
            'reopenRequestId' => (string) Str::uuid(),
        ]);
    }

    public function edit(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainAccess $access,
    ): View {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeEdit($request->user(), $organization);
        $this->assertOrganization($businessDomain, $organization->id);
        if ($businessDomain->status !== BusinessDomain::STATUS_ACTIVE) {
            abort(409, '保管中の事業領域は、再開してから編集してください。');
        }
        $businessDomain->load([
            'items' => fn ($query) => $query->where('status', BusinessDomainItem::STATUS_ACTIVE),
            'items.attributes' => fn ($query) => $query->where('status', BusinessDomainItemAttribute::STATUS_ACTIVE),
        ]);

        return view('business-domains.edit', [
            'organization' => $organization,
            'domain' => $businessDomain,
            'requestId' => (string) Str::uuid(),
            'itemKinds' => BusinessDomainItem::KINDS,
            'attributeAxes' => BusinessDomainItemAttribute::AXES,
            'directions' => BusinessDomain::DIRECTIONS,
        ]);
    }

    public function update(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate($this->domainRules(true));
        $domain = $writer->update(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $businessDomain,
            $this->domainInput($validated),
            (int) $validated['expected_version'],
            $validated['change_reason'],
            $validated['request_id'],
        );

        return redirect()->route('business-domains.show', $domain)
            ->with('status', '事業領域を更新しました。');
    }

    public function archive(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate($this->stateRules());
        $domain = $writer->archive(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $businessDomain,
            (int) $validated['expected_version'],
            $validated['change_reason'],
            $validated['request_id'],
        );

        return redirect()->route('business-domains.manage.show', $domain)->with('status', '事業領域を保管しました。');
    }

    public function reopen(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate($this->stateRules());
        $domain = $writer->reopen(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $businessDomain,
            (int) $validated['expected_version'],
            $validated['change_reason'],
            $validated['request_id'],
        );

        return redirect()->route('business-domains.manage.show', $domain)->with('status', '事業領域を再開しました。');
    }

    public function move(
        Request $request,
        BusinessDomain $businessDomain,
        BusinessDomainWriter $writer,
    ): RedirectResponse {
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);
        $domain = $writer->move(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $businessDomain,
            $validated['direction'],
            $validated['request_id'],
        );

        return redirect()->route('business-domains.manage', ['status' => $domain->status])
            ->with('status', '表示順を変更しました。');
    }

    public function revision(
        Request $request,
        BusinessDomain $businessDomain,
        int $revision,
        BusinessDomainAccess $access,
    ): View {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeHistory($request->user(), $organization);
        $this->assertOrganization($businessDomain, $organization->id);
        $record = BusinessDomainRevision::query()
            ->where('business_domain_id', $businessDomain->id)
            ->where('revision_no', $revision)
            ->with('actor')
            ->first();
        if (! $record) {
            throw (new ModelNotFoundException)->setModel(BusinessDomainRevision::class);
        }

        return view('business-domains.revision', [
            'organization' => $organization,
            'domain' => $businessDomain,
            'revision' => $record,
        ]);
    }

    public function editors(Request $request, BusinessDomainAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeOwner($request->user(), $organization);
        $memberships = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->whereIn('organization_role', [
                OrganizationUser::ORGANIZATION_ROLE_ADMIN,
                OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])
            ->with(['user', 'businessDomainEditorGrant'])
            ->orderBy('id')
            ->get();

        return view('business-domains.editors', [
            'organization' => $organization,
            'memberships' => $memberships,
            'editorRequestIds' => $memberships->mapWithKeys(
                fn (OrganizationUser $membership): array => [$membership->id => (string) Str::uuid()],
            )->all(),
        ]);
    }

    public function grantEditor(
        Request $request,
        OrganizationUser $organizationMembership,
        BusinessDomainGrantManager $manager,
    ): RedirectResponse {
        $validated = $request->validate(['request_id' => ['required', 'uuid']]);
        $manager->grant(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationMembership,
            $validated['request_id'],
        );

        return back()->with('status', '編集担当に指定しました。');
    }

    public function revokeEditor(
        Request $request,
        OrganizationUser $organizationMembership,
        BusinessDomainGrantManager $manager,
    ): RedirectResponse {
        $validated = $request->validate(['request_id' => ['required', 'uuid']]);
        $manager->revoke(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $organizationMembership,
            $validated['request_id'],
        );

        return back()->with('status', '編集担当を解除しました。');
    }

    private function domainRules(bool $update): array
    {
        $rules = [
            'request_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'what_summary' => ['nullable', 'string', 'max:10000'],
            'who_summary' => ['nullable', 'string', 'max:10000'],
            'value_proposition' => ['nullable', 'string', 'max:10000'],
            'geographic_scope_summary' => ['nullable', 'string', 'max:10000'],
            'market_position_summary' => ['nullable', 'string', 'max:10000'],
            'self_recognized_strengths' => ['nullable', 'string', 'max:10000'],
            'direction' => ['nullable', Rule::in(array_keys(BusinessDomain::DIRECTIONS))],
            'direction_memo' => ['nullable', 'string', 'max:10000'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.public_id' => ['nullable', 'string', 'max:26'],
            'items.*.kind' => ['required', Rule::in(BusinessDomainItem::KINDS)],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:10000'],
            'items.*.attributes' => ['nullable', 'array', 'max:30'],
            'items.*.attributes.*.public_id' => ['nullable', 'string', 'max:26'],
            'items.*.attributes.*.axis' => ['required', Rule::in(BusinessDomainItemAttribute::AXES)],
            'items.*.attributes.*.label' => ['required', 'string', 'max:255'],
            'items.*.attributes.*.value_text' => ['required', 'string', 'max:10000'],
        ];
        if ($update) {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
            $rules['change_reason'] = ['required', 'string', 'max:2000'];
        }

        return $rules;
    }

    private function stateRules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'change_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    private function domainInput(array $validated): array
    {
        $input = collect($validated)->except(['request_id', 'expected_version', 'change_reason'])->all();
        $input['items'] = $validated['items'] ?? [];

        return $input;
    }

    private function assertOrganization(BusinessDomain $domain, int $organizationId): void
    {
        if ($domain->organization_id !== $organizationId) {
            throw (new ModelNotFoundException)->setModel(BusinessDomain::class);
        }
    }

    private function isOwner(Request $request, BusinessDomainAccess $access): bool
    {
        $membership = $access->membership($request->user(), $request->attributes->get('currentCompany'));

        return $membership?->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER;
    }
}
