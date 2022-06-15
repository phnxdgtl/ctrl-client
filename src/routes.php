<?php

use Illuminate\Support\Facades\Route;

use Phnxdgtl\CtrlClient\CtrlClientController;

Route::middleware(['ctrl-client'])->prefix('ctrl-client')->group(function () {
	Route::get('test',  [CtrlClientController::class, 'test']);
	// TODO: these any() routes should all be post(), I think. And why is getDatabaseStructure a (valid) get request?
	Route::any('get-table-data', [CtrlClientController::class, 'getTableData']);
	Route::any('get-object-data', [CtrlClientController::class, 'getObjectData']);
	Route::any('put-object-data', [CtrlClientController::class, 'putObjectData']);
	Route::any('delete-object', [CtrlClientController::class, 'deleteObject']);

	Route::any('export-data', [CtrlClientController::class, 'exportData']);

	Route::any('get-possible-values', [CtrlClientController::class, 'getPossibleValues']);
	Route::any('get-related-values', [CtrlClientController::class, 'getRelatedValues']);
	Route::any('put-related-values', [CtrlClientController::class, 'putRelatedValues']);
	Route::get('get-database-structure', [CtrlClientController::class, 'getDatabaseStructure']);

	Route::post('sync-search', [CtrlClientController::class, 'buildTypesenseIndex']);

	Route::post('get-client-version', [CtrlClientController::class, 'getClientVersion']);
});