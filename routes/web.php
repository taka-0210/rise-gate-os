<?php

use App\Http\Controllers\ActionExecutionController;
use App\Http\Controllers\AiConnectionController;
use App\Http\Controllers\Auth\AccountEmailController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BusinessDomainController;
use App\Http\Controllers\Client\ClientCompanyAccountController;
use App\Http\Controllers\Client\ClientController;
use App\Http\Controllers\CompanyAnnualPlanController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyFinanceController;
use App\Http\Controllers\CompanyHomeController;
use App\Http\Controllers\CompanyLoanController;
use App\Http\Controllers\CompanyMemberAccessController;
use App\Http\Controllers\CompanyObservationController;
use App\Http\Controllers\CompanyRepaymentCapacityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevelopmentSetupController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\InvitationOnboardingController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationManagementController;
use App\Http\Controllers\OwnerOnboardingController;
use App\Http\Controllers\Project\AiChatController;
use App\Http\Controllers\Project\AiProposalController;
use App\Http\Controllers\Project\AiProposalItemReviewController;
use App\Http\Controllers\Project\AiRequestController;
use App\Http\Controllers\Project\ImprovementController;
use App\Http\Controllers\Project\ImprovementEffortController;
use App\Http\Controllers\Project\ImprovementOutputController;
use App\Http\Controllers\Project\ProjectActualController;
use App\Http\Controllers\Project\ProjectAppController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectHandoffController;
use App\Http\Controllers\Project\ProjectInternalNoteController;
use App\Http\Controllers\Project\ProjectLocalConnectionController;
use App\Http\Controllers\Project\ProjectMemberController;
use App\Http\Controllers\Project\ProjectTimelineSnapshotController;
use App\Http\Controllers\Project\RoadmapController;
use App\Http\Controllers\Project\TaskController;
use App\Http\Controllers\Project\TimelineScheduleController;
use App\Http\Controllers\Project\WorkspaceOrderController;
use App\Http\Controllers\ProjectExecutionController;
use App\Http\Controllers\ProjectExecutionAiController;
use App\Http\Controllers\PublicEstimateController;
use App\Http\Controllers\SessionTokenController;
use App\Http\Controllers\StandaloneAppController;
use App\Http\Controllers\SystemAdmin\AuthenticatedSessionController as SystemAdminSessionController;
use App\Http\Controllers\SystemAdmin\MemberController as SystemAdminMemberController;
use App\Http\Controllers\SystemAdmin\OwnerOnboardingController as SystemAdminOwnerOnboardingController;
use App\Http\Controllers\SystemAdmin\WorkspaceController as SystemAdminWorkspaceController;
use App\Http\Controllers\UserAvatarController;
use App\Http\Controllers\Workspace\WorkspaceBusinessProfileController;
use App\Http\Controllers\Workspace\WorkspaceController;
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

    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:account-mail')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:account-token')->name('password.update');
});

Route::get('/account/email/confirm/{requestId}', [AccountEmailController::class, 'confirm'])
    ->middleware(['signed:relative', 'throttle:account-token'])
    ->name('account.email.confirm');

Route::get('/invitations/{invitation}/claim', [InvitationOnboardingController::class, 'claim'])
    ->middleware('throttle:account-token')
    ->name('invitations.claim');
Route::get('/invitation/onboarding', [InvitationOnboardingController::class, 'show'])
    ->name('invitations.onboarding');
Route::post('/invitation/onboarding/register', [InvitationOnboardingController::class, 'register'])
    ->middleware('throttle:invitation')
    ->name('invitations.register');

Route::get('/owner-onboarding/{onboarding}/claim', [OwnerOnboardingController::class, 'claim'])
    ->middleware('throttle:account-token')
    ->name('owner-onboarding.claim');
Route::get('/owner-onboarding', [OwnerOnboardingController::class, 'show'])
    ->name('owner-onboarding.show');
Route::post('/owner-onboarding/register', [OwnerOnboardingController::class, 'register'])
    ->middleware('throttle:owner-onboarding')
    ->name('owner-onboarding.register');

Route::get('/system-admin/login', [SystemAdminSessionController::class, 'create'])->name('system-admin.login');
Route::post('/system-admin/login', [SystemAdminSessionController::class, 'store'])->name('system-admin.login.store');

Route::get('/estimate-review/{token}', [PublicEstimateController::class, 'show'])->name('public.estimates.show');
Route::post('/estimate-review/{token}/respond', [PublicEstimateController::class, 'respond'])->name('public.estimates.respond');

Route::prefix('apps/{projectApp}')->name('apps.')->group(function (): void {
    Route::get('/', [StandaloneAppController::class, 'run'])->name('run');
    Route::post('/login', [StandaloneAppController::class, 'login'])->middleware('throttle:standalone-app-login')->name('login');
    Route::post('/logout', [StandaloneAppController::class, 'logout'])->name('logout');
    Route::get('/accounts', [StandaloneAppController::class, 'accounts'])->name('accounts');
    Route::post('/accounts', [StandaloneAppController::class, 'storeAccount'])->middleware('throttle:30,1,app-accounts:')->name('accounts.store');
    Route::put('/accounts/{appAccount}', [StandaloneAppController::class, 'updateAccount'])->middleware('throttle:30,1,app-accounts:')->name('accounts.update');
    Route::get('/data', [StandaloneAppController::class, 'readData'])->name('data.read');
    Route::put('/data', [StandaloneAppController::class, 'writeData'])->middleware('throttle:120,1,app-data:')->name('data.write');
});

Route::middleware(['auth', 'active-user', 'credential-session'])->group(function (): void {
    Route::get('/session/token', [SessionTokenController::class, '__invoke'])->name('session.token');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/account', [ProfileController::class, 'show'])->name('account.profile');
    Route::patch('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::post('/account/avatar', [UserAvatarController::class, 'update'])->name('account.avatar.update');
    Route::get('/users/{user}/avatar', [UserAvatarController::class, 'show'])->name('users.avatar');
    Route::post('/invitation/onboarding/prepare', [InvitationOnboardingController::class, 'prepare'])
        ->middleware('throttle:invitation')
        ->name('invitations.prepare');
    Route::post('/invitation/onboarding/accept', [InvitationOnboardingController::class, 'accept'])
        ->middleware('throttle:invitation')
        ->name('invitations.accept');
    Route::post('/owner-onboarding/prepare', [OwnerOnboardingController::class, 'prepare'])
        ->middleware('throttle:owner-onboarding')
        ->name('owner-onboarding.prepare');
    Route::post('/owner-onboarding/complete', [OwnerOnboardingController::class, 'complete'])
        ->middleware('throttle:owner-onboarding')
        ->name('owner-onboarding.complete');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');
    Route::post('/account/email/verify', [AccountEmailController::class, 'verifyCurrent'])->middleware('throttle:account-mail')->name('account.email.verify');
    Route::post('/account/email/change', [AccountEmailController::class, 'requestChange'])->middleware('throttle:account-mail')->name('account.email.change');
    Route::post('/account/email/change/resend', [AccountEmailController::class, 'resendChange'])->middleware('throttle:account-mail')->name('account.email.change.resend');
    Route::delete('/account/email/change', [AccountEmailController::class, 'cancelChange'])->name('account.email.change.cancel');
    Route::get('/development/setup', [DevelopmentSetupController::class, 'index'])->name('development.setup');
    Route::get('/development/download', [DevelopmentSetupController::class, 'download'])->name('development.download');

    Route::get('/companies', [CompanyController::class, 'index'])->middleware('workspace-mode')->name('companies.index');
    Route::post('/companies/{organization}/switch', [CompanyController::class, 'switch'])->middleware('workspace-mode')->name('companies.switch');

    Route::middleware(['workspace-mode', 'company'])->group(function (): void {
        Route::get('/company', CompanyHomeController::class)->name('company.home');
        Route::get('/company/business-domains', [BusinessDomainController::class, 'index'])->name('business-domains.index');
        Route::get('/company/business-domains/create', [BusinessDomainController::class, 'create'])->name('business-domains.create');
        Route::post('/company/business-domains', [BusinessDomainController::class, 'store'])->name('business-domains.store');
        Route::get('/company/business-domains/editors', [BusinessDomainController::class, 'editors'])->name('business-domains.editors');
        Route::post('/company/business-domains/editors/{organizationMembership}', [BusinessDomainController::class, 'grantEditor'])->name('business-domains.editors.grant');
        Route::delete('/company/business-domains/editors/{organizationMembership}', [BusinessDomainController::class, 'revokeEditor'])->name('business-domains.editors.revoke');
        Route::get('/company/business-domains/manage', [BusinessDomainController::class, 'manage'])->name('business-domains.manage');
        Route::get('/company/business-domains/manage/{businessDomain}', [BusinessDomainController::class, 'manageShow'])->name('business-domains.manage.show');
        Route::get('/company/business-domains/{businessDomain}', [BusinessDomainController::class, 'show'])->name('business-domains.show');
        Route::get('/company/business-domains/{businessDomain}/edit', [BusinessDomainController::class, 'edit'])->name('business-domains.edit');
        Route::put('/company/business-domains/{businessDomain}', [BusinessDomainController::class, 'update'])->name('business-domains.update');
        Route::post('/company/business-domains/{businessDomain}/move', [BusinessDomainController::class, 'move'])->name('business-domains.move');
        Route::post('/company/business-domains/{businessDomain}/archive', [BusinessDomainController::class, 'archive'])->name('business-domains.archive');
        Route::post('/company/business-domains/{businessDomain}/reopen', [BusinessDomainController::class, 'reopen'])->name('business-domains.reopen');
        Route::get('/company/business-domains/{businessDomain}/revisions/{revision}', [BusinessDomainController::class, 'revision'])->whereNumber('revision')->name('business-domains.revisions.show');
        Route::get('/company/today', [ActionExecutionController::class, 'today'])->name('action-executions.today');
        Route::post('/company/today/refresh', [ActionExecutionController::class, 'refresh'])->name('action-executions.refresh');
        Route::get('/company/projects/{project}/actions/{action}/executions', [ActionExecutionController::class, 'show'])->name('action-executions.show');
        Route::post('/company/projects/{project}/actions/{action}/executions/configure', [ActionExecutionController::class, 'configure'])->name('action-executions.configure');
        Route::post('/company/projects/{project}/actions/{action}/executions/draft', [ActionExecutionController::class, 'suggestDraft'])->middleware('throttle:20,1')->name('action-executions.draft');
        Route::post('/company/projects/{project}/actions/{action}/executions/pause', [ActionExecutionController::class, 'pause'])->name('action-executions.pause');
        Route::post('/company/projects/{project}/actions/{action}/executions/resume', [ActionExecutionController::class, 'resume'])->name('action-executions.resume');
        Route::post('/company/action-executions/{execution}/complete', [ActionExecutionController::class, 'complete'])->name('action-executions.complete');
        Route::post('/company/action-executions/{execution}/skip', [ActionExecutionController::class, 'skip'])->name('action-executions.skip');
        Route::post('/company/action-executions/{execution}/retry', [ActionExecutionController::class, 'retry'])->name('action-executions.retry');
        Route::get('/company/projects', [ProjectExecutionController::class, 'index'])->name('project-execution.index');
        Route::get('/company/projects/create', [ProjectExecutionController::class, 'create'])->name('project-execution.create');
        Route::post('/company/projects', [ProjectExecutionController::class, 'store'])->name('project-execution.store');
        Route::get('/company/projects/{project}', [ProjectExecutionController::class, 'show'])->name('project-execution.show');
        Route::get('/company/projects/{project}/ai-plan', [ProjectExecutionAiController::class, 'index'])->name('project-execution.ai.index');
        Route::post('/company/projects/{project}/ai-plan/requests', [ProjectExecutionAiController::class, 'storeRequest'])->name('project-execution.ai.requests.store');
        Route::get('/company/projects/{project}/ai-plan/{aiProposal}', [ProjectExecutionAiController::class, 'show'])->name('project-execution.ai.proposals.show');
        Route::post('/company/projects/{project}/ai-plan/{aiProposal}/revision', [ProjectExecutionAiController::class, 'requestRevision'])->name('project-execution.ai.proposals.revision');
        Route::post('/company/projects/{project}/ai-plan/{aiProposal}/approve', [ProjectExecutionAiController::class, 'approve'])->name('project-execution.ai.proposals.approve');
        Route::post('/company/projects/{project}/ai-plan/{aiProposal}/apply', [ProjectExecutionAiController::class, 'apply'])->name('project-execution.ai.proposals.apply');
        Route::get('/company/projects/{project}/manage', [ProjectExecutionController::class, 'manage'])->name('project-execution.manage');
        Route::put('/company/projects/{project}', [ProjectExecutionController::class, 'updateProject'])->name('project-execution.update');
        Route::post('/company/projects/{project}/roadmaps', [ProjectExecutionController::class, 'storeRoadmap'])->name('project-execution.roadmaps.store');
        Route::post('/company/projects/{project}/roadmaps/{roadmap}/themes', [ProjectExecutionController::class, 'storeTheme'])->name('project-execution.themes.store');
        Route::post('/company/projects/{project}/actions', [ProjectExecutionController::class, 'storeAction'])->name('project-execution.actions.store');
        Route::post('/company/projects/{project}/actions/{action}/transition', [ProjectExecutionController::class, 'transitionAction'])->name('project-execution.actions.transition');
        Route::put('/company/projects/{project}/actions/{action}', [ProjectExecutionController::class, 'updateAction'])->name('project-execution.actions.update');
        Route::post('/company/projects/{project}/actions/{action}/move', [ProjectExecutionController::class, 'moveAction'])->name('project-execution.actions.move');
        Route::put('/company/projects/{project}/visibility', [ProjectExecutionController::class, 'updateVisibility'])->name('project-execution.visibility.update');
        Route::post('/company/projects/{project}/members', [ProjectExecutionController::class, 'storeMember'])->name('project-execution.members.store');
        Route::delete('/company/projects/{project}/members/{member}', [ProjectExecutionController::class, 'destroyMember'])->name('project-execution.members.destroy');
        Route::put('/company/projects/{project}/reviewer', [ProjectExecutionController::class, 'updateReviewer'])->name('project-execution.reviewer.update');
        Route::put('/company/projects/{project}/owner', [ProjectExecutionController::class, 'transferOwner'])->name('project-execution.owner.update');
        Route::post('/company/projects/{project}/complete', [ProjectExecutionController::class, 'completeParent'])->name('project-execution.complete');
        Route::post('/company/projects/{project}/review', [ProjectExecutionController::class, 'reviewProject'])->name('project-execution.review');
        Route::post('/company/projects/{project}/archive', [ProjectExecutionController::class, 'archiveEntity'])->name('project-execution.archive');
        Route::post('/company/projects/{project}/reopen', [ProjectExecutionController::class, 'reopenEntity'])->name('project-execution.reopen');
        Route::get('/company/observations', [CompanyObservationController::class, 'index'])->name('company-observations.index');
        Route::post('/company/observations', [CompanyObservationController::class, 'store'])->name('company-observations.store');
        Route::get('/company/observations/{companyObservation}', [CompanyObservationController::class, 'show'])->name('company-observations.show');
        Route::post('/company/observations/{companyObservation}/dialogue', [CompanyObservationController::class, 'respond'])->name('company-observations.respond');
        Route::post('/company/observations/{companyObservation}/senses/{companySense}/improvements', [CompanyObservationController::class, 'storeImprovement'])->name('company-observations.improvements.store');
        Route::get('/company/finance', [CompanyFinanceController::class, 'index'])->name('company-finance.index');
        Route::put('/company/finance/settings', [CompanyFinanceController::class, 'updateSettings'])->name('company-finance.settings.update');
        Route::get('/company/finance/pl', [CompanyFinanceController::class, 'profitLoss'])->name('company-finance.pl.index');
        Route::get('/company/finance/pl/create', [CompanyFinanceController::class, 'create'])->name('company-finance.pl.create');
        Route::post('/company/finance/pl/preview', [CompanyFinanceController::class, 'preview'])->name('company-finance.pl.preview');
        Route::post('/company/finance/pl', [CompanyFinanceController::class, 'store'])->name('company-finance.pl.store');
        Route::get('/company/finance/pl/bulk', [CompanyFinanceController::class, 'bulk'])->name('company-finance.pl.bulk');
        Route::post('/company/finance/pl/bulk/preview', [CompanyFinanceController::class, 'bulkPreview'])->name('company-finance.pl.bulk.preview');
        Route::post('/company/finance/pl/bulk', [CompanyFinanceController::class, 'bulkStore'])->name('company-finance.pl.bulk.store');
        Route::post('/company/finance/pl/confirm-drafts', [CompanyFinanceController::class, 'confirmDrafts'])->name('company-finance.pl.confirm-drafts');
        Route::get('/company/finance/pl/{period}/edit', [CompanyFinanceController::class, 'edit'])->name('company-finance.pl.edit');
        Route::put('/company/finance/pl/{period}', [CompanyFinanceController::class, 'update'])->name('company-finance.pl.update');
        Route::post('/company/finance/pl/{period}/confirm', [CompanyFinanceController::class, 'confirm'])->name('company-finance.pl.confirm');
        Route::get('/company/finance/repayment-capacity', [CompanyRepaymentCapacityController::class, 'index'])->name('company-finance.repayment-capacity.index');
        Route::put('/company/finance/repayment-capacity', [CompanyRepaymentCapacityController::class, 'update'])->name('company-finance.repayment-capacity.update');
        Route::post('/company/finance/repayment-capacity/simulate', [CompanyRepaymentCapacityController::class, 'simulate'])->name('company-finance.repayment-capacity.simulate');
        Route::put('/company/finance/repayment-capacity/scenario', [CompanyRepaymentCapacityController::class, 'saveScenario'])->name('company-finance.repayment-capacity.scenario.save');
        Route::get('/company/finance/plan', [CompanyAnnualPlanController::class, 'index'])->name('company-finance.annual-plan.index');
        Route::put('/company/finance/plan', [CompanyAnnualPlanController::class, 'update'])->name('company-finance.annual-plan.update');
        Route::get('/company/finance/{section}', [CompanyFinanceController::class, 'placeholder'])->name('company-finance.section');
        Route::get('/company/loans', [CompanyLoanController::class, 'index'])->name('company-loans.index');
        Route::get('/company/loans/schedule', [CompanyLoanController::class, 'schedule'])->name('company-loans.schedule');
        Route::get('/company/loans/create', [CompanyLoanController::class, 'create'])->name('company-loans.create');
        Route::post('/company/loans/preview', [CompanyLoanController::class, 'preview'])->name('company-loans.preview');
        Route::post('/company/loans', [CompanyLoanController::class, 'store'])->name('company-loans.store');
        Route::get('/company/loans/bulk', [CompanyLoanController::class, 'bulk'])->name('company-loans.bulk');
        Route::post('/company/loans/bulk/preview', [CompanyLoanController::class, 'bulkPreview'])->name('company-loans.bulk.preview');
        Route::post('/company/loans/bulk', [CompanyLoanController::class, 'bulkStore'])->name('company-loans.bulk.store');
        Route::post('/company/loans/confirm-drafts', [CompanyLoanController::class, 'confirmDrafts'])->name('company-loans.confirm-drafts');
        Route::get('/company/loans/{loan}/edit', [CompanyLoanController::class, 'edit'])->name('company-loans.edit');
        Route::post('/company/loans/{loan}/save', [CompanyLoanController::class, 'update'])->name('company-loans.save');
        Route::put('/company/loans/{loan}', [CompanyLoanController::class, 'update'])->name('company-loans.update');
        Route::put('/company/finance/repayment-capacity/loans/{loan}/extra-repayment-funding', [CompanyRepaymentCapacityController::class, 'updateExtraRepaymentFunding'])->name('company-finance.repayment-capacity.extra-repayment-funding');
        Route::delete('/company/loans/{loan}/balance-snapshots/{snapshot}', [CompanyLoanController::class, 'destroyBalanceSnapshot'])->name('company-loans.balance-snapshots.destroy');
        Route::post('/company/loans/{loan}/confirm', [CompanyLoanController::class, 'confirm'])->name('company-loans.confirm');
        Route::get('/company/members', [CompanyMemberAccessController::class, 'index'])->name('company-members.index');
        Route::put('/company/members/{user}', [CompanyMemberAccessController::class, 'update'])->name('company-members.update');
        Route::get('/company/organization', [OrganizationManagementController::class, 'index'])->name('organization-management.index');
        Route::post('/company/organization/standard-workspace', [OrganizationManagementController::class, 'initializeStandardWorkspace'])->name('organization-management.standard-workspace.store');
        Route::post('/company/organization/invitations', [OrganizationInvitationController::class, 'store'])->middleware('throttle:invitation')->name('organization-management.invitations.store');
        Route::post('/company/organization/invitations/{organizationInvitation}/resend', [OrganizationInvitationController::class, 'resend'])->middleware('throttle:invitation')->name('organization-management.invitations.resend');
        Route::delete('/company/organization/invitations/{organizationInvitation}', [OrganizationInvitationController::class, 'revoke'])->middleware('throttle:invitation')->name('organization-management.invitations.revoke');
        Route::put('/company/organization/memberships/{organizationMembership}/role', [OrganizationManagementController::class, 'updateRole'])->name('organization-management.memberships.role');
        Route::put('/company/organization/memberships/{organizationMembership}/position', [OrganizationManagementController::class, 'updatePosition'])->name('organization-management.memberships.position');
        Route::patch('/company/organization/memberships/{organizationMembership}/lifecycle', [OrganizationManagementController::class, 'updateMembershipLifecycle'])->name('organization-management.memberships.lifecycle');
        Route::post('/company/organization/groups', [OrganizationManagementController::class, 'storeGroup'])->name('organization-management.groups.store');
        Route::put('/company/organization/groups/{organizationGroup}', [OrganizationManagementController::class, 'updateGroup'])->name('organization-management.groups.update');
        Route::delete('/company/organization/groups/{organizationGroup}', [OrganizationManagementController::class, 'archiveGroup'])->name('organization-management.groups.archive');
        Route::post('/company/organization/groups/{organizationGroup}/members', [OrganizationManagementController::class, 'addGroupMember'])->name('organization-management.groups.members.store');
        Route::delete('/company/organization/groups/{organizationGroup}/members/{organizationMembership}', [OrganizationManagementController::class, 'removeGroupMember'])->name('organization-management.groups.members.destroy');
        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
        Route::post('/workspaces/{workspace}/projects', [WorkspaceController::class, 'projects'])->name('workspaces.projects');
        Route::get('/workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
        Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    });

    Route::middleware('system-admin')->prefix('system-admin')->name('system-admin.')->group(function (): void {
        Route::post('/exit', [SystemAdminSessionController::class, 'exit'])->name('exit');
        Route::get('/members', [SystemAdminMemberController::class, 'index'])->name('members.index');
        Route::get('/owner-onboardings', [SystemAdminOwnerOnboardingController::class, 'index'])->name('owner-onboardings.index');
        Route::post('/owner-onboardings', [SystemAdminOwnerOnboardingController::class, 'store'])->middleware('throttle:owner-onboarding')->name('owner-onboardings.store');
        Route::post('/owner-onboardings/{ownerOnboarding}/resend', [SystemAdminOwnerOnboardingController::class, 'resend'])->middleware('throttle:owner-onboarding')->name('owner-onboardings.resend');
        Route::delete('/owner-onboardings/{ownerOnboarding}', [SystemAdminOwnerOnboardingController::class, 'revoke'])->middleware('throttle:owner-onboarding')->name('owner-onboardings.revoke');
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

    Route::middleware(['workspace-mode', 'company', 'workspace'])->group(function (): void {
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
        Route::post('/clients/{client}/company-account', [ClientCompanyAccountController::class, 'store'])->name('clients.company-account.store');
        Route::get('/projects/schedule', [ProjectController::class, 'schedule'])->name('projects.schedule');
        Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::get('/projects/{project}/apps', [ProjectAppController::class, 'index'])->name('projects.apps.index');
        Route::post('/projects/{project}/apps', [ProjectAppController::class, 'store'])->middleware('throttle:20,1,app-publish:')->name('projects.apps.store');
        Route::get('/projects/{project}/apps/{projectApp}/source', [ProjectAppController::class, 'source'])->name('projects.apps.source');
        Route::put('/projects/{project}/apps/{projectApp}', [ProjectAppController::class, 'update'])->middleware('throttle:20,1,app-publish:')->name('projects.apps.update');
        Route::get('/projects/{project}/workspace', [ProjectController::class, 'workspace'])->name('projects.workspace');
        Route::get('/projects/{project}/handoffs', [ProjectHandoffController::class, 'index'])->name('projects.handoffs.index');
        Route::post('/projects/{project}/handoffs', [ProjectHandoffController::class, 'store'])->name('projects.handoffs.store');
        Route::post('/projects/{project}/handoffs/{handoff}/approve', [ProjectHandoffController::class, 'approve'])->name('projects.handoffs.approve');
        Route::post('/projects/{project}/handoffs/{handoff}/reject', [ProjectHandoffController::class, 'reject'])->name('projects.handoffs.reject');
        Route::get('/projects/{project}/actuals', [ProjectActualController::class, 'index'])->name('projects.actuals.index');
        Route::post('/projects/{project}/actuals', [ProjectActualController::class, 'store'])->name('projects.actuals.store');
        Route::delete('/projects/{project}/actuals/{actual}', [ProjectActualController::class, 'destroy'])->name('projects.actuals.destroy');
        Route::get('/projects/{project}/timeline-snapshots', [ProjectTimelineSnapshotController::class, 'index'])->name('projects.timeline-snapshots.index');
        Route::post('/projects/{project}/timeline-snapshots', [ProjectTimelineSnapshotController::class, 'store'])->name('projects.timeline-snapshots.store');
        Route::get('/projects/{project}/timeline-snapshots/{timelineSnapshot}', [ProjectTimelineSnapshotController::class, 'show'])->name('projects.timeline-snapshots.show');
        Route::get('/projects/{project}/timeline-snapshots/{timelineSnapshot}/restore', [ProjectTimelineSnapshotController::class, 'restoreConfirmation'])->name('projects.timeline-snapshots.restore-confirmation');
        Route::post('/projects/{project}/timeline-snapshots/{timelineSnapshot}/restore', [ProjectTimelineSnapshotController::class, 'restore'])->name('projects.timeline-snapshots.restore');
        Route::patch('/projects/{project}/workspace/order', [WorkspaceOrderController::class, 'update'])->name('projects.workspace.order');
        Route::patch('/projects/{project}/workspace/preference', [WorkspaceOrderController::class, 'preference'])->name('projects.workspace.preference');
        Route::post('/projects/{project}/local-connection', [ProjectLocalConnectionController::class, 'store'])->name('projects.local-connection.store');
        Route::delete('/projects/{project}/local-connection', [ProjectLocalConnectionController::class, 'destroy'])->name('projects.local-connection.destroy');
        Route::post('/projects/{project}/ai-chat/messages', [AiChatController::class, 'store'])->middleware('throttle:20,1')->name('projects.ai-chat.messages.store');
        Route::get('/projects/{project}/ai-chat/messages/{message}/image', [AiChatController::class, 'image'])->name('projects.ai-chat.messages.image');
        Route::post('/projects/{project}/ai-chat/messages/{message}/image-saved', [AiChatController::class, 'markImageSaved'])->name('projects.ai-chat.messages.image-saved');
        Route::post('/projects/{project}/ai-chat/messages/{message}/file-change/applied', [AiChatController::class, 'markFileChangeApplied'])->name('projects.ai-chat.messages.file-change.applied');
        Route::post('/projects/{project}/ai-chat/messages/{message}/file-change/rejected', [AiChatController::class, 'markFileChangeRejected'])->name('projects.ai-chat.messages.file-change.rejected');
        Route::get('/projects/{project}/client-plan', [ProjectController::class, 'clientPlan'])->name('projects.client-plan');
        Route::get('/projects/{project}/business-media/{type}', [WorkspaceBusinessProfileController::class, 'projectMedia'])->name('projects.business-media');
        Route::post('/projects/{project}/internal-notes', [ProjectInternalNoteController::class, 'store'])->name('projects.internal-notes.store');
        Route::delete('/projects/{project}/internal-notes/{internalNote}', [ProjectInternalNoteController::class, 'destroy'])->name('projects.internal-notes.destroy');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/view', [ProjectInternalNoteController::class, 'view'])->name('projects.internal-notes.attachments.view');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/excel', [ProjectInternalNoteController::class, 'excelViewer'])->name('projects.internal-notes.attachments.excel');
        Route::get('/projects/{project}/internal-notes/{internalNote}/attachments/{attachment}/download', [ProjectInternalNoteController::class, 'download'])->name('projects.internal-notes.attachments.download');
        Route::get('/projects/{project}/ai-proposals', [AiProposalController::class, 'index'])->name('projects.ai-proposals.index');
        Route::get('/projects/{project}/ai-proposals/{aiProposal}', [AiProposalController::class, 'show'])->name('projects.ai-proposals.show');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/approve', [AiProposalController::class, 'approve'])->name('projects.ai-proposals.approve');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/apply', [AiProposalController::class, 'apply'])->name('projects.ai-proposals.apply');
        Route::post('/projects/{project}/ai-proposals/{aiProposal}/undo', [AiProposalController::class, 'undo'])->name('projects.ai-proposals.undo');
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
