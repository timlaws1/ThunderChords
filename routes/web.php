<?php

use App\Http\Controllers\Api\MedleyController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\SetlistController;
use App\Http\Controllers\Api\ShareController;
use App\Http\Controllers\Api\SongController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/s/{token}', [ShareController::class, 'page'])->where('token', '[A-Za-z0-9_-]{16,40}');
Route::get('/api/shared/{token}', [ShareController::class, 'api'])->where('token', '[A-Za-z0-9_-]{16,40}');

Route::middleware('auth')->group(function () {
    Route::get('/', [PageController::class, 'library'])->name('home');
    Route::get('/song', [PageController::class, 'song'])->name('song');
    Route::get('/setlists', [PageController::class, 'setlists'])->name('setlists');
    Route::get('/setlist', [PageController::class, 'setlist'])->name('setlist');
    Route::get('/medley', [PageController::class, 'medley'])->name('medley');

    Route::get('/account', [ProfileController::class, 'edit'])->name('account');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/api/me', MeController::class);
    Route::get('/api/prefs/{key}', [PreferenceController::class, 'show'])->where('key', '.*');
    Route::put('/api/prefs/{key}', [PreferenceController::class, 'update'])->where('key', '.*');

    Route::get('/api/songs', [SongController::class, 'index']);
    Route::get('/api/songs/{id}', [SongController::class, 'show'])->where('id', '[a-z0-9-]+');
    Route::post('/api/songs/{id}/opened', [SongController::class, 'opened'])->where('id', '[a-z0-9-]+');
    Route::post('/api/songs/preview', [SongController::class, 'preview']);
    Route::post('/api/songs/import', [SongController::class, 'import']);
    Route::post('/api/songs/{id}/reimport', [SongController::class, 'reimport'])->where('id', '[a-z0-9-]+');
    Route::post('/api/songs/{id}/replace', [SongController::class, 'replace'])->where('id', '[a-z0-9-]+');
    Route::put('/api/songs/{id}', [SongController::class, 'update'])->where('id', '[a-z0-9-]+');
    Route::delete('/api/songs/{id}', [SongController::class, 'destroy'])->where('id', '[a-z0-9-]+');

    Route::get('/api/setlists', [SetlistController::class, 'index']);
    Route::post('/api/setlists', [SetlistController::class, 'store']);
    Route::get('/api/setlists/{id}', [SetlistController::class, 'show'])->where('id', '[a-z0-9-]+');
    Route::get('/api/setlists/{id}/full', [SetlistController::class, 'full'])->where('id', '[a-z0-9-]+');
    Route::put('/api/setlists/{id}', [SetlistController::class, 'update'])->where('id', '[a-z0-9-]+');
    Route::delete('/api/setlists/{id}', [SetlistController::class, 'destroy'])->where('id', '[a-z0-9-]+');

    Route::get('/api/medleys', [MedleyController::class, 'index']);
    Route::post('/api/medleys', [MedleyController::class, 'store']);
    Route::get('/api/medleys/{id}', [MedleyController::class, 'show'])->where('id', '[a-z0-9-]+');
    Route::get('/api/medleys/{id}/full', [MedleyController::class, 'full'])->where('id', '[a-z0-9-]+');
    Route::put('/api/medleys/{id}', [MedleyController::class, 'update'])->where('id', '[a-z0-9-]+');
    Route::delete('/api/medleys/{id}', [MedleyController::class, 'destroy'])->where('id', '[a-z0-9-]+');

    Route::get('/api/shares', [ShareController::class, 'index']);
    Route::post('/api/shares', [ShareController::class, 'store']);
    Route::delete('/api/shares/{token}', [ShareController::class, 'destroy'])->where('token', '[A-Za-z0-9_-]{16,40}');
});

require __DIR__.'/auth.php';
