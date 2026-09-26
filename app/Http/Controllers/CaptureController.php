<?php

namespace App\Http\Controllers;

use App\Models\Capture;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\User;
use App\Services\Capture\CaptureAccess;
use App\Services\Capture\CaptureWriter;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CaptureController extends Controller
{
    public function create(Request $r): View
    {
        return view('captures.create', ['recipients' => $this->recipients($r), 'operationId' => (string) Str::uuid()]);
    }

    public function store(Request $r, CaptureWriter $w): RedirectResponse
    {
        $d = $r->validate(['operation_id' => ['required', 'uuid'], 'type' => ['required', 'in:self,request,tell_later'], 'body' => ['required', 'string', 'max:4000'], 'recipient_user_id' => ['nullable', 'integer'], 'notification_timing' => ['required', 'in:now,specified,next_window'], 'notify_at' => ['nullable', 'date']]);
        $c = $w->create($r->user(), $r->attributes->get('currentCompany'), $d);

        return redirect()->route('captures.show', $c)->with('status', 'COに預けました');
    }

    public function operation(Request $r, string $operation, CaptureAccess $a): JsonResponse
    {
        abort_unless(Str::isUuid($operation), 404);
        $c = Capture::query()->where('create_operation_id', $operation)->first();
        if (! $c) {
            return response()->json(['status' => 'not_found'], 404);
        }abort_unless($a->canRead($r->user(), $c), 404);

        return response()->json(['status' => 'saved', 'url' => route('captures.show', $c)]);
    }

    public function index(Request $r, CaptureAccess $a): View
    {
        $org = $r->attributes->get('currentCompany');
        $box = $r->string('box')->value() === 'created' ? 'created' : 'received';
        $state = in_array($r->string('state')->value(), ['closed', 'converted'], true) ? $r->string('state')->value() : 'open';
        abort_unless($a->canParticipate($r->user(), $org), 404);
        $membership = OrganizationUser::query()->where('organization_id', $org->id)->where('user_id', $r->user()->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->firstOrFail();
        $party = $box === 'created' ? 'creator' : 'recipient';
        $captures = Capture::query()
            ->with(['creator', 'recipient'])
            ->where('organization_id', $org->id)
            ->where($party.'_user_id', $r->user()->id)
            ->where($party.'_access_epoch', $membership->access_epoch)
            ->where($party.'_credential_generation', $r->user()->credential_generation)
            ->where('status', $state)
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('captures.index', compact('captures', 'box', 'state'));
    }

    public function show(Request $r, Capture $c, CaptureAccess $a): View
    {
        abort_unless($c->organization_id === $r->attributes->get('currentCompany')->id && $a->canRead($r->user(), $c), 404);

        return view('captures.show', ['capture' => $c->load(['creator', 'recipient', 'events.actor', 'actionRelation.task'])]);
    }

    public function acknowledge(Request $r, Capture $c, CaptureWriter $w): RedirectResponse
    {
        $d = $this->mutation($r);
        $w->acknowledge($r->user(), $c, $d['operation_id'], (int) $d['version']);

        return back()->with('status', '受け取りを記録しました');
    }

    public function close(Request $r, Capture $c, CaptureWriter $w): RedirectResponse
    {
        $d = $this->mutation($r);
        $w->close($r->user(), $c, $d['operation_id'], (int) $d['version']);

        return back()->with('status', '整理終了にしました');
    }

    public function cancel(Request $r, Capture $c, CaptureWriter $w): RedirectResponse
    {
        $d = $this->mutation($r);
        $w->cancel($r->user(), $c, $d['operation_id'], (int) $d['version']);

        return back()->with('status', '取り消しました');
    }

    public function promote(Request $r, Capture $c, CaptureAccess $a, ProjectExecutionAccess $p): View
    {
        abort_unless($a->canRead($r->user(), $c) && $c->creator_user_id === $r->user()->id, 404);
        $projects = Project::query()->where('organization_id', $c->organization_id)->get()->filter(fn ($x) => $p->canCreateAction($r->user(), $x) && $p->canRead($c->recipient, $x) && $p->isExecutionMember($c->recipient, $x));

        return view('captures.promote', ['capture' => $c, 'projects' => $projects, 'operationId' => (string) Str::uuid()]);
    }

    public function promoteStore(Request $r, Capture $c, CaptureWriter $w): RedirectResponse
    {
        $d = $r->validate(['operation_id' => ['required', 'uuid'], 'capture_version' => ['required', 'integer'], 'project_id' => ['required', 'integer'], 'project_version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'done_condition' => ['required', 'string', 'max:2000'], 'reviewer_user_id' => ['nullable', 'integer'], 'due_date' => ['nullable', 'date'], 'confirm_project_visibility' => ['accepted']]);
        $task = $w->promote($r->user(), $c, Project::query()->findOrFail($d['project_id']), $d, (int) $d['capture_version'], (int) $d['project_version']);

        return redirect('/company/projects/'.$task->project_id.'#action-'.$task->id)->with('status', 'Actionへ昇格しました');
    }

    private function mutation(Request $r): array
    {
        return $r->validate(['operation_id' => ['required', 'uuid'], 'version' => ['required', 'integer']]);
    }

    private function recipients(Request $r)
    {
        $org = $r->attributes->get('currentCompany');

        return User::query()->where('is_active', true)->whereHas('organizationMemberships', fn ($q) => $q->where('organization_id', $org->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE))->orderBy('name')->get();
    }
}
