<?php

namespace App\Http\Controllers;

use App\Models\CompanyImprovement;
use App\Models\CompanyObservation;
use App\Models\CompanySense;
use App\Models\OrganizationUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyObservationController extends Controller
{
    public function index(Request $request): View
    {
        return $this->render($request);
    }

    public function show(Request $request, CompanyObservation $companyObservation): View
    {
        $this->ensureCurrentCompany($request, $companyObservation);

        return $this->render($request, $companyObservation);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureContributor($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'occurred_on' => ['nullable', 'date'],
            'source_type' => ['required', Rule::in(array_keys(CompanyObservation::sourceTypes()))],
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = $request->attributes->get('currentCompany');
        $observation = CompanyObservation::create([
            ...$validated,
            'organization_id' => $company->id,
            'observer_user_id' => $request->user()->id,
            'observed_at' => now(),
            'status' => CompanyObservation::STATUS_RECORDED,
            'importance' => CompanyObservation::IMPORTANCE_UNREVIEWED,
        ]);

        return redirect()
            ->route('company-observations.show', $observation)
            ->with('status', 'Observationを記録しました。次に、この変化の意味を一緒に考えます。');
    }

    public function respond(Request $request, CompanyObservation $companyObservation): RedirectResponse
    {
        $this->ensureCurrentCompany($request, $companyObservation);
        $this->ensureContributor($request);

        $validated = $request->validate([
            'importance' => ['required', Rule::in([
                CompanyObservation::IMPORTANCE_IMPORTANT,
                CompanyObservation::IMPORTANCE_WATCHING,
                CompanyObservation::IMPORTANCE_NOT_NOW,
                CompanyObservation::IMPORTANCE_UNCLEAR,
            ])],
            'interpretation' => ['nullable', 'string', 'max:10000', 'required_if:importance,'.CompanyObservation::IMPORTANCE_IMPORTANT],
            'hypothesis' => ['nullable', 'string', 'max:10000'],
            'next_observation' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $companyObservation, $validated): void {
            $sense = null;
            if (filled($validated['interpretation'] ?? null)) {
                $sense = CompanySense::create([
                    'organization_id' => $companyObservation->organization_id,
                    'interpretation' => $validated['interpretation'],
                    'hypothesis' => $validated['hypothesis'] ?? null,
                    'status' => $validated['importance'] === CompanyObservation::IMPORTANCE_IMPORTANT
                        ? CompanySense::STATUS_SUPPORTED
                        : CompanySense::STATUS_UNCERTAIN,
                    'proposed_by' => $request->user()->id,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

                $companyObservation->senses()->attach($sense->id, [
                    'relationship_type' => 'interprets',
                    'created_by' => $request->user()->id,
                ]);
            }

            $status = match ($validated['importance']) {
                CompanyObservation::IMPORTANCE_IMPORTANT => CompanyObservation::STATUS_INTERPRETED,
                CompanyObservation::IMPORTANCE_NOT_NOW => CompanyObservation::STATUS_CLOSED,
                default => CompanyObservation::STATUS_WATCHING,
            };

            $companyObservation->update([
                'importance' => $validated['importance'],
                'status' => $status,
                'next_observation' => $validated['next_observation'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return redirect()
            ->route('company-observations.show', $companyObservation)
            ->with('status', 'Company Dialogueへの回答を記録しました。');
    }

    public function storeImprovement(
        Request $request,
        CompanyObservation $companyObservation,
        CompanySense $companySense,
    ): RedirectResponse {
        $this->ensureCurrentCompany($request, $companyObservation);
        $this->ensureContributor($request);
        abort_unless(
            $companySense->organization_id === $companyObservation->organization_id
            && $companyObservation->senses()->whereKey($companySense->id)->exists(),
            404,
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'desired_state' => ['required', 'string', 'max:10000'],
            'expected_effect' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::in(array_keys(CompanyImprovement::priorities()))],
        ]);

        DB::transaction(function () use ($request, $companyObservation, $companySense, $validated): void {
            $improvement = CompanyImprovement::create([
                ...$validated,
                'organization_id' => $companyObservation->organization_id,
                'background' => $companyObservation->body,
                'current_state' => $companyObservation->body,
                'reason' => $companySense->interpretation,
                'hypothesis' => $companySense->hypothesis,
                'status' => CompanyImprovement::STATUS_DISCOVERED,
                'owner_user_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);

            $improvement->observations()->attach($companyObservation->id, [
                'relationship_type' => 'discovered_from',
                'created_by' => $request->user()->id,
            ]);
            $improvement->senses()->attach($companySense->id, [
                'relationship_type' => 'suggests',
                'created_by' => $request->user()->id,
            ]);

            $companyObservation->update([
                'status' => CompanyObservation::STATUS_DEVELOPED,
                'importance' => CompanyObservation::IMPORTANCE_IMPORTANT,
            ]);
        });

        return redirect()
            ->route('company-observations.show', $companyObservation)
            ->with('status', 'ObservationからCompany Improvementを育て始めました。');
    }

    private function render(Request $request, ?CompanyObservation $selectedObservation = null): View
    {
        $company = $request->attributes->get('currentCompany');
        $selectedObservation?->load(['observer', 'senses.improvements']);

        return view('company-observations.index', [
            'company' => $company,
            'observations' => CompanyObservation::query()
                ->where('organization_id', $company->id)
                ->withCount(['senses', 'improvements'])
                ->with('observer')
                ->latest('observed_at')
                ->limit(30)
                ->get(),
            'selectedObservation' => $selectedObservation,
            'sourceTypes' => CompanyObservation::sourceTypes(),
            'importanceLabels' => CompanyObservation::importanceLabels(),
            'priorities' => CompanyImprovement::priorities(),
            'canContribute' => $this->canContribute($request),
        ]);
    }

    private function ensureCurrentCompany(Request $request, CompanyObservation $observation): void
    {
        $company = $request->attributes->get('currentCompany');
        abort_unless($company && $observation->organization_id === $company->id, 404);
    }

    private function ensureContributor(Request $request): void
    {
        abort_unless($this->canContribute($request), 403);
    }

    private function canContribute(Request $request): bool
    {
        $company = $request->attributes->get('currentCompany');
        $membership = OrganizationUser::query()
            ->where('organization_id', $company->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return $membership && $membership->role !== OrganizationUser::ROLE_VIEWER;
    }
}
