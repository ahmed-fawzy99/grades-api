<?php

use App\Http\Controllers\Api\V1\GradeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'message' => 'Welcome to the API v1',
        ]);
    });
    Route::apiResource('grades', GradeController::class)->only(['index', 'store', 'show', 'destroy']);
});
