<?php

use Illuminate\Support\Facades\Route;
use Justrau\Orrery\Http\Controllers\JobController;

Route::prefix('api')->group(function (): void {
    Route::get('/workload', 'WorkloadController@index');
    Route::get('/workers', 'WorkersController@index');
    Route::get('/health', 'HealthController@index');
    Route::get('/counts', 'CountsController@index');

    Route::get('/control', 'ControlController@index');
    Route::post('/control', 'ControlController@store');

    // The tags API.
    Route::get('/tags', 'TagsController@index');
    Route::post('/tags/monitored', 'TagsController@store');
    Route::delete('/tags/monitored', 'TagsController@destroyByQuery');
    Route::delete('/tags/monitored/{tag}', 'TagsController@destroy')->where('tag', '.*');

    // The per-job writes (uuid-constrained).
    Route::post('/jobs/{uuid}/retry', 'JobWriteController@retry')->where('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::post('/jobs/{uuid}/forget', 'JobWriteController@forget')->where('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

    // The bulk runs.
    Route::get('/bulk', 'BulkController@index');
    Route::post('/bulk/freeze', 'BulkController@freeze');
    Route::post('/bulk/run', 'BulkController@run');
    Route::get('/bulk/{ref}', 'BulkController@show');

    // The list engine.
    Route::get('/jobs', 'JobListController@index');
    // The stuck signals, above the other /jobs/… routes.
    Route::get('/jobs/stuck', 'StuckJobsController@index');

    // The job detail and its history, uuid-constrained.
    Route::get('/jobs/{uuid}', 'JobController@show')->where('uuid', JobController::UUID_PATTERN);
    Route::get('/jobs/{uuid}/history', 'JobController@history')->where('uuid', JobController::UUID_PATTERN);
    Route::get('/jobs/{uuid}/history/{id}', 'JobController@entry')->where(['uuid' => JobController::UUID_PATTERN, 'id' => JobController::ENTRY_PATTERN]);

    // The batches, read data-only.
    Route::get('/batches', 'BatchesController@index');
    Route::get('/batches/{id}', 'BatchesController@show');

    Route::get('/throughput', 'ThroughputController@index');
    Route::get('/markers', 'MarkersController@index');
    Route::get('/queues/{connection}/{queue}/history', 'QueueHistoryController@show')->where('queue', '.+');
    Route::get('/settings/retention', 'RetentionSettingsController@index');
    Route::get('/classes', 'JobClassesController@index');
    Route::get('/classes/series', 'JobClassesController@series');

    Route::get('/failures/groups', 'FailureGroupsController@index');
    Route::get('/failures/groups/{key}', 'FailureGroupsController@show');

    // The operator's resolve of an operation.
    Route::post('/operations/{id}/resolve', 'OperationsController@resolve');

    // Reserves the API namespace: an unknown API path is a JSON 404, never the SPA page below,
    // also the bare `api` with or without its trailing slash.
    Route::get('/', 'ApiNotFoundController@show');
    Route::get('/{any}', 'ApiNotFoundController@show')->where('any', '.*');
});

Route::get('/{view?}', 'HomeController@index')->where('view', '(.*)')->name('orrery.index');
