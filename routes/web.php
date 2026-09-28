<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any}', function () {
    $indexPath = public_path('index.html');
    if (file_exists($indexPath)) {
        return file_get_contents($indexPath);
    }
    return response("File index.html belum ditemukan.", 404);
})->where('any', '.*');
