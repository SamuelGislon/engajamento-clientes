<?php

use App\Http\Controllers\CommercialReportController;
use App\Http\Controllers\CommercialSettingsController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyPortfolioController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerScoreController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\JourneyEnrollmentController;
use App\Http\Controllers\JourneyTemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Middleware\EnsureOrganizationSelected;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/invitations/{token}', [OrganizationInvitationController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('invitations.show');
Route::post('/invitations/{token}/accept', [OrganizationInvitationController::class, 'accept'])
    ->middleware(['auth', 'verified'])
    ->where('token', '[a-f0-9]{64}')
    ->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('/organization/switch', OrganizationSwitchController::class)->name('organizations.switch');

    Route::middleware(EnsureOrganizationSelected::class)->group(function (): void {
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        Route::patch('/onboarding/organization', [OnboardingController::class, 'updateOrganization'])->name('onboarding.organization.update');
        Route::patch('/onboarding/relationship', [OnboardingController::class, 'updateRelationship'])->name('onboarding.relationship.update');
        Route::patch('/onboarding/journeys', [OnboardingController::class, 'updateJourneys'])->name('onboarding.journeys.update');
        Route::post('/onboarding/continue', [OnboardingController::class, 'advance'])->name('onboarding.continue');
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/imports', [DataImportController::class, 'index'])->name('imports.index');
        Route::post('/imports', [DataImportController::class, 'store'])->name('imports.store');
        Route::post('/imports/{dataImport}/mapping', [DataImportController::class, 'saveMapping'])->whereNumber('dataImport')->name('imports.mapping');
        Route::post('/imports/{dataImport}/resolutions', [DataImportController::class, 'saveResolutions'])->whereNumber('dataImport')->name('imports.resolutions');
        Route::post('/imports/{dataImport}/validate', [DataImportController::class, 'validateImport'])->whereNumber('dataImport')->name('imports.validate');
        Route::post('/imports/{dataImport}/confirm', [DataImportController::class, 'confirm'])->whereNumber('dataImport')->name('imports.confirm');
        Route::post('/imports/{dataImport}/resume', [DataImportController::class, 'resume'])->whereNumber('dataImport')->name('imports.resume');
        Route::get('/imports/{dataImport}', [DataImportController::class, 'show'])->whereNumber('dataImport')->name('imports.show');
        Route::get('/reports', CommercialReportController::class)->name('reports.index');
        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::get('/teams/{team}', [TeamController::class, 'show'])->whereNumber('team')->name('teams.show');
        Route::patch('/teams/{team}', [TeamController::class, 'update'])->whereNumber('team')->name('teams.update');
        Route::put('/teams/{team}/members', [TeamController::class, 'syncMembers'])->whereNumber('team')->name('teams.members.sync');
        Route::get('/organization/users', [OrganizationInvitationController::class, 'index'])->name('organization.users.index');
        Route::patch('/organization/users/{user}', [OrganizationMemberController::class, 'update'])->whereNumber('user')->name('organization.users.update');
        Route::post('/organization/invitations', [OrganizationInvitationController::class, 'store'])->name('organization.invitations.store');
        Route::post('/organization/invitations/{invitation}/resend', [OrganizationInvitationController::class, 'resend'])->whereNumber('invitation')->name('organization.invitations.resend');
        Route::delete('/organization/invitations/{invitation}', [OrganizationInvitationController::class, 'revoke'])->whereNumber('invitation')->name('organization.invitations.revoke');
        Route::get('/commercial-settings', [CommercialSettingsController::class, 'edit'])->name('commercial-settings.edit');
        Route::patch('/commercial-settings', [CommercialSettingsController::class, 'update'])->name('commercial-settings.update');
        Route::post('/commercial-settings/score-recalculations/{recalculationId}/resume', [CommercialSettingsController::class, 'resumeScoreRecalculation'])
            ->whereNumber('recalculationId')
            ->name('commercial-settings.score-recalculations.resume');
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/commercial/classification', [CustomerScoreController::class, 'index'])->name('commercial.classification.index');
        Route::get('/sales/{sale}/edit', [SaleController::class, 'edit'])->whereNumber('sale')->name('sales.edit');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task')->name('tasks.show');
        Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
        Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->whereNumber('task')->name('tasks.edit');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->whereNumber('task')->name('tasks.complete');
        Route::patch('/tasks/{task}/postpone', [TaskController::class, 'postpone'])->whereNumber('task')->name('tasks.postpone');
        Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel'])->whereNumber('task')->name('tasks.cancel');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/portfolio', [CompanyPortfolioController::class, 'index'])->name('companies.portfolio.index');
        Route::post('/companies/portfolio/transfer', [CompanyPortfolioController::class, 'transfer'])->name('companies.portfolio.transfer');
        Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('companies.show');
        Route::post('/companies/{company}/journeys', [JourneyEnrollmentController::class, 'store'])->whereNumber('company')->name('companies.journeys.store');
        Route::get('/journeys', [JourneyTemplateController::class, 'index'])->name('journeys.index');
        Route::get('/journeys/templates/create', [JourneyTemplateController::class, 'create'])->name('journeys.templates.create');
        Route::post('/journeys/templates', [JourneyTemplateController::class, 'store'])->name('journeys.templates.store');
        Route::get('/journeys/templates/{template}/edit', [JourneyTemplateController::class, 'edit'])->whereNumber('template')->name('journeys.templates.edit');
        Route::patch('/journeys/templates/{template}', [JourneyTemplateController::class, 'update'])->whereNumber('template')->name('journeys.templates.update');
        Route::patch('/journeys/settings', [JourneyTemplateController::class, 'updateSettings'])->name('journeys.settings.update');
        Route::post('/journeys/enrollments/{enrollment}/pause', [JourneyEnrollmentController::class, 'pause'])->whereNumber('enrollment')->name('journeys.enrollments.pause');
        Route::post('/journeys/enrollments/{enrollment}/resume', [JourneyEnrollmentController::class, 'resume'])->whereNumber('enrollment')->name('journeys.enrollments.resume');
        Route::post('/journeys/enrollments/{enrollment}/cancel', [JourneyEnrollmentController::class, 'cancel'])->whereNumber('enrollment')->name('journeys.enrollments.cancel');
        Route::patch('/companies/{company}/owner', [CompanyController::class, 'updateOwner'])->whereNumber('company')->name('companies.owner.update');
        Route::post('/companies/{company}/activities', [CompanyController::class, 'storeActivity'])->whereNumber('company')->name('companies.activities.store');
        Route::post('/companies/{company}/contacts', [CompanyController::class, 'attachContact'])->whereNumber('company')->name('companies.contacts.store');
        Route::post('/companies/{company}/sales', [SaleController::class, 'store'])->whereNumber('company')->name('companies.sales.store');
        Route::patch('/companies/{company}/commercial-score', [CustomerScoreController::class, 'update'])->whereNumber('company')->name('companies.commercial-score.update');
        Route::patch('/sales/{sale}', [SaleController::class, 'update'])->whereNumber('sale')->name('sales.update');
        Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->whereNumber('sale')->name('sales.cancel');

        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('/contacts/create', [ContactController::class, 'create'])->name('contacts.create');
        Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});

require __DIR__.'/settings.php';
