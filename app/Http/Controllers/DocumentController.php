<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $request->attributes->get('currentWorkspace');
        return view('documents.index', [
            'estimateCount' => $workspace->estimates()->count(),
            'draftEstimateCount' => $workspace->estimates()->where('status', 'draft')->count(),
        ]);
    }
}
