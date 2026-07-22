<?php

use App\Http\Controllers\AiConnectionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\Project\AiProposalController;
use App\Http\Controllers\Project\AiProposalItemReviewController;
use App\Http\Controllers\Project\AiRequestController;
use App\Http\Controllers\Project\AiChatController;
use App\Http\Controllers\Project\ImprovementController;
use App\Http\Controllers\Project\ImprovementEffortController;
use App\Http\Controllers\Project\ImprovementOutputController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectInternalNoteController;
use App\Http\Controllers\Project\ProjectMemberController;
use App\Http\Controllers\Project\RoadmapController;
use App\Http\Controllers\Project\TaskController;
use App\Http\Controllers\Project\TimelineScheduleController;
use App\Http\Controllers\SystemAdmin\AuthenticatedSessionController as SystemAdminSessionController;
use App\Http\Controllers\SystemAdmin\MemberController as SystemAdminMemberController;
use App\Http\Controllers\SystemAdmin\WorkspaceController as SystemAdminWorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceBusinessProfileController;
use App\Http\Controllers\WorkspaceAiSettingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::get('/system-admin/login', [SystemAdminSessionController::class, 'create'])->name('system-admin.login');
Route::post('/system-admin/login', [SystemAdminSessionController::class, 'store'])->name('system-admin.login.store');

Route::get('/estimate-review/{token}', [\App\Http\Controllers\PublicEstimateController::class, 'show'])->name('public.estimates.show');
Route::post('/estimate-review/{token}/respond', [\App\Http\Controllers\PublicEstimateController::class, 'respond'])->name('public.estimates.respond');

Route::middleware(['auth', 'active-user'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->middleware('workspace-mode')->name('workspaces.index');
    Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->middleware('workspace-mode')->name('workspaces.create');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('workspace-mode')->name('workspaces.store');
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->middleware('workspace-mode')->name('workspaces.switch');
    Route::post('/workspaces/{workspace}/projects', [WorkspaceController::class, 'projects'])->middleware('workspace-mode')->name('workspaces.projects');
    Route::get('/workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->middleware('workspace-mode')->name('workspaces.edit');
    Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->middleware('workspace-mode')->name('workspaces.update');

    Route::middleware('system-admin')->prefix('system-admin')->name('system-admin.')->group(function (): void {
        Route::post('/exit', [SystemAdminSessionController::class, 'exit'])->name('exit');
        Route::get('/members', [SystemAdminMemberController::class, 'index'])->name('members.index');
        Route::post('/members', [SystemAdminMemberController::class, 'store'])->name('members.store');
        Route::get('/members/{user}/edit', [SystemAdminMemberController::class, 'edit'])->name('members.edit');
        Route::put('/members/{user}', [SystemAdminMemberController::class, 'update'])->name('members.update');
        Route::post('/members/{user}/workspaces', [SystemAdminMemberController::class, 'storeWorkspace'])->name('members.workspaces.store');
        Route::put('/members/{user}/workspaces/{workspace}', [SystemAdminMemberController::class, 'updateWorkspace'])->name('members.workspaces.update');
        Route::delete('/members/{user}/workspaces/{workspace}', [SystemAdminMemberController::class, 'destroyWorkspace'])->name('members.workspaces.destroy');
        Route::get('/workspaces', [SystemAdminWorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('/workspaces/{workspace}/edit', [SystemAdminWorkspaceController::class, 'edit'])->name('workspaces.edit');
        Route::put('/workspaces/{workspace}', [SystemAdminWorkspaceController::class, 'update'])->name('workspaces.update');
        Route::put('/workspaces/{workspace}/status', [SystemAdminWorkspaceController::class, 'updateStatus'])->name('workspaces.status.update');
    });

    Route::middleware(['workspace-mode', 'workspace'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::view('/development-guide', 'guides.development')->name('development-guide');
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/estimates', [EstimateController::class, 'index'])->name('estimates.index');
        Route::get('/projects/{project}/estimates/create', [EstimateController::class, 'create'])->name('projects.estimates.create');
        Route::post('/projects/{project}/estimates', [EstimateController::class, 'store'])->name('projects.estimates.store');
        Route::get('/estimates/{estimate}', [EstimateController::class, 'show'])->name('estimates.show');
        Route::get('/estimates/{estimate}/edit', [EstimateController::class, 'edit'])->name('estimates.edit');
        Route::put('/estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
        Route::delete('/estimates/{estimate}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
        Route::post('/estimates/{estimate}/status', [EstimateController::class, 'updateStatus'])->name('estimates.status');
        Route::post('/estimates/{estimate}/revise', [EstimateController::class, 'revise'])->name('estimates.revise');
        Route::post('/estimates/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('estimates.duplicate');
        Route::get('/estimates/{estimate}/media/{type}', [EstimateController::class, 'media'])->name('estimates.media');
        Route::get('/ai-connections', [AiConnectionController::class, 'index'])->name('ai-connections.index');
        Route::post('/ai-connections', [AiConnectionController::class, 'store'])->name('ai-connections.store');
        Route::delete('/ai-connections/{aiAccessKey}', [AiConnectionController::class, 'destroy'])->name('ai-connections.destroy');
        Route::get('/ai-settings', [WorkspaceAiSettingController::class, 'edit'])->name('ai-settings.edit');
        Route::put('/ai-settings', [WorkspaceAiSettingController::class, 'update'])->name('ai-settings.update');
        Route::get('/workspace-business-profile', [WorkspaceBusinessProfileController::class, 'edit'])->name('workspace-business-profile.edit');
        Route::put('/workspace-business-profile', [WorkspaceBusinessProfileController::class, 'update'])->name('workspace-business-profile.update');
        Route::get('/workspace-business-profile/media/{type}', [WorkspaceBusinessProfileController::class, 'media'])->name('workspace-business-profile.media');
        Route::resource('clients', ClientController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::get('/projects/schedule', [ProjectController::class, 'schedule'])->name('projects.schedule');
        Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::get('/projects/{project}/workspace', [ProjectController::class, 'workspace'])->name('projects.workspace');
        Route::post('/projects/{project}/ai-chat/messages', [AiChatController::class, 'store'])->middleware('throttle:20,1')->name('projects.ai-chat.messages.store');
        Route::get('/projects/{project}/client-plan', [ProjectController::class, 'clientPlan'])->name('projects.client-plan');
        Route::get('/projects/{project}/business-media/{type}', [WorkspaceBusinessProfileController::class, 'projectMedia'])->name('projects.business-media');
        Route::post('/projects/{project}/internal-notes', [ProjectInternalNoteController::class, 'store'])->name('projects.internal-notes.store');
        Route::delete('/projects/{project}/internal-notes/{internalNote}', [ProjectInternalNoteController::class, 'destroy'])->name('projects.internal-notes.destroy');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/view', [ProjectInternalNoteController::class, 'view'])->name('projects.internal-notes.attachments.view');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/excel', [ProjectInternalNoteController::class, 'excelViewer'])->name('projects.internal-notes.attachments.excel');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/download', [ProjectInternalNoteController::class, 'download'])->name('projects.internal-notes.attachments.download');
        Route::get('/projects/{project}/ai-proposals', [AiProposalController::class, 'index'])->name('projects.ai-proposals.index');
        Route::get('/projects/{project}/ai-proposals/{aiProposal}', [AiProposalController::class, 'show'])->name('projects.ai-proposals.show');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/apply', [AiProposalController::class, 'apply'])->name('projects.ai-proposals.apply');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/reject', [AiProposalController::class, 'reject'])->name('projects.ai-proposals.reject');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/handoff', [AiProposalController::class, 'handoff'])->name('projects.ai-proposals.handoff');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/items/{item}/review', [AiProposalItemReviewController::class, 'store'])->name('projects.ai-proposals.items.review.store');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/roadmap-reviews', [AiProposalItemReviewController::class, 'storeRoadmap'])->name('projects.ai-proposals.roadmap-reviews.store');
        Route::delete('/projects/{project}/ai-proposals/{aiProposal}/items/{item}/review', [AiProposalItemReviewController::class, 'destroy'])->name('projects.ai-proposals.items.review.destroy');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/request-revision', [AiProposalItemReviewController::class, 'requestRevision'])->name('projects.ai-proposals.request-revision');
        Route::post('/projects/{project}/ai-requests', [AiRequestController::class, 'store'])->name('projects.ai-requests.store');
        Route::get('/projects/{project}/ai-requests/{aiRequest}/attachments/{attachment}', [AiRequestController::class, 'download'])->name('projects.ai-requests.attachments.download');
        Route::get('/projects/{project}/manage', [ProjectController::class, 'legacy'])->name('projects.legacy');
        Route::post('/projects/{project}/move', [ProjectController::class, 'move'])->name('projects.move');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::resource('projects.improvements', ImprovementController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::patch('/projects/{project}/improvement-efforts', [ImprovementEffortController::class, 'update'])->name('projects.improvement-efforts.update');
        Route::post('/projects/{project}/roadmaps', [RoadmapController::class, 'store'])->name('projects.roadmaps.store');
        Route::get('/projects/{project}/roadmaps/create', [RoadmapController::class, 'create'])->name('projects.roadmaps.create');
        Route::get('/projects/{project}/roadmaps/{roadmap}/edit', [RoadmapController::class, 'edit'])->name('projects.roadmaps.edit');
        Route::put('/projects/{project}/roadmaps/{roadmap}', [RoadmapController::class, 'update'])->name('projects.roadmaps.update');
        Route::delete('/projects/{project}/roadmaps/{roadmap}', [RoadmapController::class, 'destroy'])->name('projects.roadmaps.destroy');
        Route::post('/projects/{project}/improvements/{improvement}/roadmap', [RoadmapController::class, 'assignImprovement'])->name('projects.improvements.roadmap.assign');
        Route::delete('/projects/{project}/improvements/{improvement}/roadmap', [RoadmapController::class, 'removeImprovement'])->name('projects.improvements.roadmap.remove');
        Route::resource('projects.tasks', TaskController::class)->only(['create', 'store', 'show', 'edit', 'update', 'destroy']);
        Route::patch('/projects/{project}/timeline/{type}/{entity}', [TimelineScheduleController::class, 'update'])->name('projects.timeline.update');
        Route::post('/projects/{project}/improvements/{improvement}/outputs/tasks', [ImprovementOutputController::class, 'storeTask'])->name('projects.improvements.outputs.tasks.store');
        Route::post('/projects/{project}/improvements/{improvement}/outputs/projects', [ImprovementOutputController::class, 'storeProject'])->name('projects.improvements.outputs.projects.store');
        Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
        Route::delete('/projects/{project}/members/{projectMember}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');
    });
});
