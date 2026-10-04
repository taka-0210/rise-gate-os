<?php

namespace App\Http\Controllers;

use App\Services\CompanyContextReader\CompanyContextReaderComposer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyContextReaderController extends Controller
{
    public function __invoke(Request $request, CompanyContextReaderComposer $composer): View
    {
        $validated = $request->validate(['annual' => ['nullable', 'string', 'max:64']]);
        $organization = $request->attributes->get('currentCompany');
        $reader = $composer->compose($request->user(), $organization, $validated['annual'] ?? null);

        return view('company-context-reader.show', [
            'organization' => $organization,
            'reader' => $reader,
        ]);
    }
}
