<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('dashboard.index', [
            'currentWorkspace' => $request->attributes->get('currentWorkspace'),
            'currentWorkspaceRole' => $request->attributes->get('currentWorkspaceRole'),
        ]);
    }
}
