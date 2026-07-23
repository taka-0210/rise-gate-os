<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $companies = $request->user()
            ->organizations()
            ->withCount('workspaces')
            ->orderBy('organizations.name')
            ->get();

        if ($companies->count() === 1) {
            $request->session()->put('current_company_id', $companies->first()->id);
            $request->session()->forget('current_workspace_id');

            return redirect()->route('company.home');
        }

        return view('companies.index', compact('companies'));
    }

    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless(
            $request->user()->organizations()->where('organizations.id', $organization->id)->exists(),
            403
        );

        $request->session()->put('current_company_id', $organization->id);
        $request->session()->forget('current_workspace_id');

        return redirect()->route('company.home');
    }
}
