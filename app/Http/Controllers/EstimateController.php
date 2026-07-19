<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EstimateController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $request->attributes->get('currentWorkspace');
        return view('estimates.index', [
            'estimates' => $workspace->estimates()->with(['project', 'client'])->latest('issued_on')->latest()->paginate(20),
            'statuses' => Estimate::statuses(),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        Gate::authorize('update', $project);
        $this->assertCurrentWorkspace($request, $project->owning_workspace_id);
        $project->load(['client', 'roadmaps.improvements.tasks', 'improvements.tasks']);

        return view('estimates.create', ['project' => $project]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        $this->assertCurrentWorkspace($request, $project->owning_workspace_id);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'issued_on' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array'],
            'items.*.selected' => ['nullable', 'boolean'],
            'items.*.source_type' => ['required', Rule::in(['manual', 'roadmap', 'improvement', 'task'])],
            'items.*.source_id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', Rule::in([0, 8, 10])],
        ]);
        $items = collect($validated['items'])->filter(fn ($item) => (bool) ($item['selected'] ?? false))->values();
        if ($items->isEmpty()) throw ValidationException::withMessages(['items' => '見積明細を1件以上選択してください。']);
        $hasPackage = $items->contains(fn ($item) => $item['source_type'] === 'manual');
        $items = $items->map(function ($item) use ($hasPackage): array {
            $item['is_scope_only'] = $hasPackage && $item['source_type'] !== 'manual';
            return $item;
        });
        $items->each(function ($item) use ($project): void {
            if ($item['source_type'] !== 'manual') {
                $this->assertSourceBelongsToProject($project, $item['source_type'], (int) $item['source_id']);
            }
        });

        $estimate = DB::transaction(function () use ($request, $workspace, $project, $validated, $items): Estimate {
            $profile = $workspace->businessProfile;
            $bank = $workspace->bankAccounts()->first();
            $issuerSnapshot = $profile?->only(['legal_name','trade_name','postal_code','address_line1','address_line2','phone','email','representative_title','representative_name','invoice_registration_number','document_note']) ?? [];
            $issuerSnapshot['workspace_name'] = $workspace->name;
            $issuerSnapshot['bank_account'] = $bank?->only(['bank_name','branch_name','account_type','account_number','account_holder']);
            foreach (['logo', 'seal'] as $type) {
                $source = $profile?->{$type.'_path'};
                if ($source && Storage::disk('local')->exists($source)) {
                    $extension = pathinfo($source, PATHINFO_EXTENSION);
                    $target = "estimate-snapshots/{$workspace->id}/".uniqid($type.'-').($extension ? '.'.$extension : '');
                    Storage::disk('local')->copy($source, $target);
                    $issuerSnapshot[$type.'_path'] = $target;
                    $issuerSnapshot[$type.'_name'] = $profile->{$type.'_original_name'};
                }
            }
            $client = $project->client;
            $clientSnapshot = $client->only(['name','kana','email','phone','website','postal_code','address']);
            $number = $this->nextNumber($workspace->id, $validated['issued_on']);
            $lines = $items->map(function ($item): array {
                $amount = $item['is_scope_only']
                    ? 0
                    : (int) round((float) $item['quantity'] * (int) $item['unit_price']);
                return $item + ['amount' => $amount];
            });
            $subtotal = $lines->sum('amount');
            $discount = min((int) ($validated['discount'] ?? 0), $subtotal);
            $taxableRatio = $subtotal > 0 ? ($subtotal - $discount) / $subtotal : 0;
            $tax = (int) floor($lines->sum(fn ($line) => $line['amount'] * $taxableRatio * ((float) $line['tax_rate'] / 100)));
            $estimate = Estimate::create([
                'workspace_id' => $workspace->id, 'project_id' => $project->id, 'client_id' => $project->client_id,
                'estimate_number' => $number, 'title' => $validated['title'], 'issued_on' => $validated['issued_on'],
                'valid_until' => $validated['valid_until'] ?? null, 'status' => Estimate::STATUS_DRAFT,
                'issuer_snapshot' => $issuerSnapshot, 'client_snapshot' => $clientSnapshot,
                'subtotal' => $subtotal, 'discount' => $discount, 'tax_amount' => $tax, 'total' => $subtotal - $discount + $tax,
                'notes' => $validated['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            foreach ($lines as $index => $line) {
                $source = $line['source_type'] === 'manual'
                    ? null
                    : $this->source($project, $line['source_type'], (int) $line['source_id']);
                $estimate->items()->create([
                    'source_type' => $line['source_type'], 'source_id' => $source?->id, 'source_public_id' => $source?->public_id,
                    'is_scope_only' => $line['is_scope_only'],
                    'description' => $line['description'], 'quantity' => $line['quantity'], 'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'], 'tax_rate' => $line['tax_rate'], 'amount' => $line['amount'], 'sort_order' => $index + 1,
                ]);
            }
            return $estimate;
        });

        return redirect()->route('estimates.show', $estimate)->with('status', '見積書を下書き保存しました。');
    }

    public function show(Request $request, Estimate $estimate): View
    {
        $this->assertCurrentWorkspace($request, $estimate->workspace_id);
        $estimate->load(['items', 'project', 'client', 'creator']);
        return view('estimates.show', compact('estimate'));
    }

    public function duplicate(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->assertCurrentWorkspace($request, $estimate->workspace_id);
        Gate::authorize('update', $estimate->project);

        $copy = DB::transaction(function () use ($request, $estimate): Estimate {
            $estimate->load('items');
            $copy = $estimate->replicate([
                'public_id', 'estimate_number', 'status', 'issued_on', 'valid_until',
                'created_by', 'created_at', 'updated_at', 'deleted_at',
            ]);
            $copy->estimate_number = $this->nextNumber($estimate->workspace_id, now()->toDateString());
            $copy->title = $estimate->title.'（複製）';
            $copy->status = Estimate::STATUS_DRAFT;
            $copy->issued_on = now()->toDateString();
            $copy->valid_until = now()->addMonth()->toDateString();
            $copy->created_by = $request->user()->id;
            $copy->save();

            foreach ($estimate->items as $item) {
                $copy->items()->create($item->only([
                    'source_type', 'source_id', 'source_public_id', 'is_scope_only', 'description',
                    'quantity', 'unit', 'unit_price', 'tax_rate', 'amount', 'sort_order',
                ]));
            }

            return $copy;
        });

        return redirect()->route('estimates.show', $copy)->with('status', '見積書を複製し、下書き保存しました。');
    }

    public function media(Request $request, Estimate $estimate, string $type): StreamedResponse
    {
        $this->assertCurrentWorkspace($request, $estimate->workspace_id);
        abort_unless(in_array($type, ['logo','seal'], true), 404);
        $path = $estimate->issuer_snapshot[$type.'_path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->response($path, $estimate->issuer_snapshot[$type.'_name'] ?? basename($path), ['Content-Disposition' => 'inline']);
    }

    private function nextNumber(int $workspaceId, string $issuedOn): string
    {
        $prefix = 'EST-'.date('Ym', strtotime($issuedOn)).'-';
        $latest = Estimate::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('estimate_number', 'like', $prefix.'%')
            ->orderByDesc('estimate_number')
            ->lockForUpdate()
            ->value('estimate_number');

        return $prefix.str_pad((string) (((int) substr((string) $latest, -4)) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function source(Project $project, string $type, int $id): Roadmap|Improvement|Task
    {
        return match ($type) {
            'roadmap' => $project->roadmaps()->findOrFail($id),
            'improvement' => $project->improvements()->findOrFail($id),
            'task' => $project->tasks()->findOrFail($id),
        };
    }

    private function assertSourceBelongsToProject(Project $project, string $type, int $id): void { $this->source($project, $type, $id); }
    private function assertCurrentWorkspace(Request $request, int $workspaceId): void { abort_unless($request->attributes->get('currentWorkspace')->id === $workspaceId, 404); }
}
