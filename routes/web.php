<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminMfaController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\CandidateController;
use App\Http\Controllers\CandidateLibraryController;
use App\Http\Controllers\CandidateUpdatesController;
use App\Http\Controllers\CvController;
use App\Http\Controllers\EmployerController;
use App\Http\Controllers\EmployerNoteController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PublicJobController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupportAssistantController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicJobController::class, 'home'])->name('home');
Route::get('/inner-green-assets/lexend-latin.woff2', static fn () => response()->file(
    resource_path('vendor/threeui/src/shaders/sylva-living-world/sources/inner-green-assets/lexend-latin.woff2'),
    ['Content-Type' => 'font/woff2', 'Access-Control-Allow-Origin' => '*'],
))->name('sylva.font');
Route::post('/language', [LocaleController::class, 'update'])->middleware('throttle:30,1,locale-update:')->name('locale.update')->block();
Route::post('/assistant', [SupportAssistantController::class, 'ask'])->name('assistant.ask');
Route::get('/jobs', [PublicJobController::class, 'index'])->name('jobs.index');
Route::get('/jobs/{job}', [PublicJobController::class, 'show'])->whereNumber('job')->name('jobs.show');
Route::get('/companies/{company}', [PublicJobController::class, 'company'])->whereNumber('company')->name('companies.show');
Route::get('/companies/{company}/logo', [PublicJobController::class, 'logo'])->whereNumber('company')->name('companies.logo');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [WebAuthController::class, 'login'])->name('login');
    Route::post('/login', [WebAuthController::class, 'storeLogin'])->middleware('throttle:8,1')->name('login.store')->block();
    Route::get('/register', [WebAuthController::class, 'register'])->name('register');
    Route::post('/register', [WebAuthController::class, 'storeRegister'])->middleware('throttle:5,1')->name('register.store')->block();
});
Route::post('/demo/{role}', [WebAuthController::class, 'demo'])->middleware('throttle:15,1')->name('demo.login')->block();
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout')->block();
Route::get('/forgot-password', [PasswordRecoveryController::class, 'requestForm'])->name('password.request');
Route::post('/forgot-password', [PasswordRecoveryController::class, 'sendLink'])->middleware('throttle:password-recovery')->name('password.email')->block();
Route::get('/password/callback', [PasswordRecoveryController::class, 'callback'])->middleware('throttle:10,1')->name('password.callback')->block();
Route::get('/reset-password', [PasswordRecoveryController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:5,1')->name('password.update')->block();
Route::post('/reset-password/mfa', [PasswordRecoveryController::class, 'verifyMfa'])->middleware('throttle:password-recovery-mfa')->name('password.mfa')->block();
Route::get('/admin/login', [AdminAuthController::class, 'show'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:8,1')->name('admin.login.store')->block();
Route::middleware('account')->group(function (): void {
    Route::middleware('role:admin')->group(function (): void {
        Route::get('/admin/mfa', [AdminMfaController::class, 'show'])->name('admin.mfa');
        Route::post('/admin/mfa/enroll', [AdminMfaController::class, 'enroll'])->middleware('throttle:admin-mfa')->name('admin.mfa.enroll');
        Route::post('/admin/mfa/verify', [AdminMfaController::class, 'verify'])->middleware('throttle:admin-mfa')->name('admin.mfa.verify');
    });
    Route::get('/dashboard', [WebAuthController::class, 'dashboard'])->name('dashboard');
    Route::get('/cvs/{cv}', [CvController::class, 'show'])->middleware('throttle:30,1')->whereNumber('cv')->name('cvs.show');
    Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereNumber('application')->name('applications.show');
    Route::post('/jobs/{job}/reports', [ReportController::class, 'store'])->middleware(['role:candidate,employer', 'throttle:5,1'])->whereNumber('job')->name('reports.store');
    Route::middleware('role:candidate')->group(function (): void {
        Route::get('/candidate', [CandidateController::class, 'dashboard'])->name('candidate.dashboard');
        Route::get('/candidate/notifications', [CandidateUpdatesController::class, 'notifications'])->name('candidate.notifications');
        Route::get('/candidate/notifications/feed', [CandidateUpdatesController::class, 'feed'])->middleware('throttle:30,1')->name('candidate.notifications.feed');
        Route::get('/candidate/notifications/{notification}/email', [CandidateUpdatesController::class, 'previewEmail'])->whereNumber('notification')->name('candidate.notifications.email');
        Route::post('/candidate/notifications/{notification}/read', [CandidateUpdatesController::class, 'markRead'])->whereNumber('notification')->name('candidate.notifications.read');
        Route::get('/candidate/saved-searches', [CandidateUpdatesController::class, 'savedSearches'])->name('candidate.saved-searches');
        Route::post('/candidate/saved-searches', [CandidateUpdatesController::class, 'storeSearch'])->middleware('throttle:20,1')->name('candidate.saved-searches.store');
        Route::delete('/candidate/saved-searches/{search}', [CandidateUpdatesController::class, 'destroySearch'])->whereNumber('search')->name('candidate.saved-searches.destroy');
        Route::get('/candidate/saved-jobs', [CandidateLibraryController::class, 'saved'])->name('candidate.saved-jobs');
        Route::post('/candidate/saved-jobs/{job}', [CandidateLibraryController::class, 'save'])->whereNumber('job')->name('candidate.saved-jobs.store');
        Route::delete('/candidate/saved-jobs/{job}', [CandidateLibraryController::class, 'remove'])->whereNumber('job')->name('candidate.saved-jobs.destroy');
        Route::get('/candidate/job-history', [CandidateLibraryController::class, 'history'])->name('candidate.job-history');
        Route::post('/candidate/job-history/{job}', [CandidateLibraryController::class, 'recordView'])->middleware('throttle:60,1')->whereNumber('job')->name('candidate.job-history.store');
        Route::delete('/candidate/job-history', [CandidateLibraryController::class, 'clearHistory'])->name('candidate.job-history.clear');
        Route::get('/candidate/profile', [CandidateController::class, 'profile'])->name('candidate.profile');
        Route::get('/candidate/profile/view', [CandidateController::class, 'showProfile'])->name('candidate.profile.show');
        Route::get('/candidate/profile/avatar', [CandidateController::class, 'avatar'])->middleware('throttle:60,1')->name('candidate.profile.avatar');
        Route::put('/candidate/profile', [CandidateController::class, 'updateProfile'])->name('candidate.profile.update');
        Route::get('/candidate/cvs', [CvController::class, 'index'])->name('candidate.cvs');
        Route::post('/candidate/cvs', [CvController::class, 'store'])->middleware('throttle:cv-uploads')->name('candidate.cvs.store');
        Route::patch('/candidate/cvs/{cv}', [CvController::class, 'update'])->whereNumber('cv')->name('candidate.cvs.update');
        Route::post('/candidate/cvs/{cv}/archive', [CvController::class, 'archive'])->whereNumber('cv')->name('candidate.cvs.archive');
        Route::get('/jobs/{job}/apply', [ApplicationController::class, 'create'])->whereNumber('job')->name('candidate.apply');
        Route::post('/jobs/{job}/apply', [ApplicationController::class, 'store'])->whereNumber('job')->name('candidate.apply.store');
        Route::get('/candidate/applications', [ApplicationController::class, 'index'])->name('candidate.applications');
    });
    Route::prefix('employer')->middleware('role:employer')->group(function (): void {
        Route::get('/', [EmployerController::class, 'dashboard'])->name('employer.dashboard');
        Route::get('/company', [EmployerController::class, 'company'])->name('employer.company');
        Route::put('/company', [EmployerController::class, 'updateCompany'])->name('employer.company.update');
        Route::get('/jobs', [EmployerController::class, 'jobs'])->name('employer.jobs');
        Route::get('/jobs/create', [EmployerController::class, 'createJob'])->name('employer.jobs.create');
        Route::post('/jobs', [EmployerController::class, 'storeJob'])->name('employer.jobs.store');
        Route::get('/jobs/{job}/edit', [EmployerController::class, 'editJob'])->whereNumber('job')->name('employer.jobs.edit');
        Route::put('/jobs/{job}', [EmployerController::class, 'updateJob'])->whereNumber('job')->name('employer.jobs.update');
        Route::post('/jobs/{job}/submit', [EmployerController::class, 'submitJob'])->whereNumber('job')->name('employer.jobs.submit');
        Route::post('/jobs/{job}/close', [EmployerController::class, 'closeJob'])->whereNumber('job')->name('employer.jobs.close');
        Route::get('/jobs/{job}/applications', [EmployerController::class, 'applications'])->whereNumber('job')->name('employer.applications');
        Route::patch('/applications/{application}', [EmployerController::class, 'updateApplication'])->whereNumber('application')->name('employer.applications.update');
        Route::put('/applications/{application}/note', [EmployerNoteController::class, 'update'])->whereNumber('application')->name('employer.applications.note');
    });
    Route::prefix('admin')->middleware(['role:admin', 'admin.confirm'])->group(function (): void {
        Route::get('/', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->name('admin.users.show');
        Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->name('admin.users.status');
        Route::patch('/companies/{company}/visibility', [AdminController::class, 'updateCompanyVisibility'])->whereNumber('company')->name('admin.companies.visibility');
        Route::get('/jobs', [AdminController::class, 'jobs'])->name('admin.jobs');
        Route::get('/jobs/{job}', [AdminController::class, 'showJob'])->whereNumber('job')->name('admin.jobs.show');
        Route::post('/jobs/{job}/moderate', [AdminController::class, 'moderateJob'])->whereNumber('job')->name('admin.jobs.moderate');
        Route::get('/reports', [AdminController::class, 'reports'])->name('admin.reports');
        Route::get('/reports/{report}', [AdminController::class, 'showReport'])->whereNumber('report')->name('admin.reports.show');
        Route::patch('/reports/{report}', [AdminController::class, 'updateReport'])->whereNumber('report')->name('admin.reports.update');
        Route::get('/statistics', [AdminController::class, 'statistics'])->name('admin.statistics');
    });
});
foreach (Route::getRoutes() as $route) {
    if (in_array('account', $route->middleware(), true)) {
        $route->block(15, 15);
    }
}
