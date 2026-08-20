<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Hello World'
    ]);
});

Route::get('/users', function () {
    return response()->json([
        'message' => 'Hello World'
    ]);
});