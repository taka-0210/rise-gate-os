<?php

namespace App\Http\Controllers;

use App\Models\ManagementDesignAccessSetting;
use App\Models\ManagementDesignGrant;
use App\Models\ManagementDesignItem;
use App\Models\ManagementDesignRevision;
use App\Models\OrganizationUser;
use App\Services\ManagementDesign\ManagementDesignAccess;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
use App\Services\ManagementDesign\ManagementDesignWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManagementDesignController extends Controller
{
    public function index(Request $request, ManagementDesignAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeMembership($request->user(), $organization);
        $types = collect(ManagementDesignItem::TYPES)->map(function (string $type) use ($request, $organization, $access): array {
            $canView = $access->canView($request->user(), $organization, $type);

            return [
                'type' => $type,
                'presentation' => ManagementDesignItem::PRESENTATION[$type],
                'can_view' => $canView,
                'can_edit' => $access->canEdit($request->user(), $organization, $type),
                'item' => $canView ? $this->item($organization->id, $type, withSections: false) : null,
            ];
        });

        return view('management-design.index', [
            'organization' => $organization,
            'types' => $types,
            'canManagePermissions' => $access->canManage($request->user(), $organization),
        ]);
    }

    public function show(Request $request, string $type, ManagementDesignAccess $access): View
    {
        $this->assertType($type);
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeView($request->user(), $organization, $type);

        return view('management-design.show', [
            'organization' => $organization,
            'type' => $type,
            'presentation' => ManagementDesignItem::PRESENTATION[$type],
            'item' => $this->item($organization->id, $type),
            'canEdit' => $access->canEdit($request->user(), $organization, $type),
        ]);
    }

    public function edit(Request $request, string $type, ManagementDesignAccess $access): View
    {
        $this->assertType($type);
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeEdit($request->user(), $organization, $type);
        $item = $this->item($organization->id, $type);
        if ($item?->status === ManagementDesignItem::STATUS_ARCHIVED) {
            abort(409, '保管中の正本は、再開してから編集してください。');
        }

        return view('management-design.edit', [
            'organization' => $organization,
            'type' => $type,
            'presentation' => ManagementDesignItem::PRESENTATION[$type],
            'item' => $item,
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function update(Request $request, string $type, ManagementDesignWriter $writer): RedirectResponse
    {
        $this->assertType($type);
        $validated = $request->validate($this->officialRules());
        $this->assertTypeFields($type, $validated);
        $writer->saveOfficial(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $type,
            [
                'statement' => $validated['statement'] ?? null,
                'statement_explanation' => $validated['statement_explanation'] ?? null,
                'horizon' => $validated['horizon'] ?? null,
                'sections' => $validated['sections'] ?? [],
            ],
            (int) $validated['expected_version'],
            $validated['change_reason'] ?? null,
            $validated['request_id'],
        );

        return redirect()->route('management-design.show', $type)
            ->with('status', ManagementDesignItem::label($type).'を正本として保存しました。');
    }

    public function history(Request $request, string $type, ManagementDesignAccess $access): View
    {
        $this->assertType($type);
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeView($request->user(), $organization, $type);
        $item = $this->requiredItem($organization->id, $type);

        return view('management-design.history', [
            'organization' => $organization,
            'type' => $type,
            'presentation' => ManagementDesignItem::PRESENTATION[$type],
            'item' => $item,
            'revisions' => $item->revisions()->with('actor')->paginate(20),
            'canEdit' => $access->canEdit($request->user(), $organization, $type),
            'archiveRequestId' => (string) Str::uuid(),
            'reopenRequestId' => (string) Str::uuid(),
        ]);
    }

    public function revision(Request $request, string $type, int $revision, ManagementDesignAccess $access): View
    {
        $this->assertType($type);
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeView($request->user(), $organization, $type);
        $item = $this->requiredItem($organization->id, $type);
        $record = ManagementDesignRevision::query()
            ->where('management_design_item_id', $item->id)
            ->where('revision_no', $revision)
            ->with('actor')
            ->first();
        if (! $record) {
            throw (new ModelNotFoundException)->setModel(ManagementDesignRevision::class);
        }

        return view('management-design.revision', [
            'organization' => $organization,
            'type' => $type,
            'presentation' => ManagementDesignItem::PRESENTATION[$type],
            'item' => $item,
            'revision' => $record,
        ]);
    }

    public function archive(Request $request, string $type, ManagementDesignWriter $writer): RedirectResponse
    {
        $this->assertType($type);
        $validated = $request->validate($this->stateRules());
        $writer->archive(
            $request->user(), $request->attributes->get('currentCompany'), $type,
            (int) $validated['expected_version'], $validated['change_reason'] ?? null, $validated['request_id'],
        );

        return redirect()->route('management-design.history', $type)->with('status', '正本を保管しました。');
    }

    public function reopen(Request $request, string $type, ManagementDesignWriter $writer): RedirectResponse
    {
        $this->assertType($type);
        $validated = $request->validate($this->stateRules());
        $writer->reopen(
            $request->user(), $request->attributes->get('currentCompany'), $type,
            (int) $validated['expected_version'], $validated['change_reason'] ?? null, $validated['request_id'],
        );

        return redirect()->route('management-design.history', $type)->with('status', '正本を再開しました。');
    }

    public function permissions(Request $request, ManagementDesignAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeManage($request->user(), $organization);
        $memberships = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->with('user')
            ->orderBy('id')
            ->get();
        $settings = ManagementDesignAccessSetting::query()->where('organization_id', $organization->id)->get()->keyBy('item_type');
        $grants = ManagementDesignGrant::query()->where('organization_id', $organization->id)->get()
            ->groupBy('item_type')->map(fn ($typeGrants) => $typeGrants->keyBy('organization_user_id'));

        return view('management-design.permissions', [
            'organization' => $organization,
            'memberships' => $memberships,
            'settings' => $settings,
            'grants' => $grants,
            'requestIds' => collect(ManagementDesignItem::TYPES)->mapWithKeys(
                fn (string $type): array => [$type => (string) Str::uuid()],
            ),
        ]);
    }

    public function updatePermissions(Request $request, string $type, ManagementDesignPermissionManager $manager): RedirectResponse
    {
        $this->assertType($type);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'view_scope' => ['required', Rule::in(ManagementDesignAccessSetting::VIEW_SCOPES)],
            'grants' => ['nullable', 'array'],
            'grants.*' => ['array'],
            'grants.*.can_view' => ['nullable', 'boolean'],
            'grants.*.can_edit' => ['nullable', 'boolean'],
        ]);
        $manager->update(
            $request->user(), $request->attributes->get('currentCompany'), $type,
            $validated['view_scope'], $validated['grants'] ?? [], $validated['request_id'],
        );

        return redirect()->route('management-design.permissions')
            ->with('status', ManagementDesignItem::label($type).'の権限を更新しました。');
    }

    private function officialRules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'statement' => ['nullable', 'string', 'max:50000'],
            'statement_explanation' => ['nullable', 'string', 'max:50000'],
            'horizon' => ['nullable', 'string', 'max:255'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'sections' => ['nullable', 'array', 'max:100'],
            'sections.*.public_id' => ['nullable', 'string', 'size:26'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.body' => ['required', 'string', 'max:50000'],
            'sections.*.explanation' => ['nullable', 'string', 'max:50000'],
            'sections.*.horizon' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function stateRules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function assertTypeFields(string $type, array $validated): void
    {
        if ($type === ManagementDesignItem::TYPE_VISION) {
            return;
        }
        if (filled($validated['horizon'] ?? null)
            || collect($validated['sections'] ?? [])->contains(fn (array $section): bool => filled($section['horizon'] ?? null))) {
            throw ValidationException::withMessages(['horizon' => 'HorizonはVisionだけに設定できます。']);
        }
    }

    private function assertType(string $type): void
    {
        abort_unless(in_array($type, ManagementDesignItem::TYPES, true), 404);
    }

    private function item(int $organizationId, string $type, bool $withSections = true): ?ManagementDesignItem
    {
        return ManagementDesignItem::query()
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->when($withSections, fn ($query) => $query->with('sections'))
            ->first();
    }

    private function requiredItem(int $organizationId, string $type): ManagementDesignItem
    {
        $item = $this->item($organizationId, $type);
        if (! $item) {
            throw (new ModelNotFoundException)->setModel(ManagementDesignItem::class);
        }

        return $item;
    }
}
