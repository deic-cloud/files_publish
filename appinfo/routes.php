<?php

declare(strict_types=1);

return [
	'ocs' => [
		// Targets available to the file action + the publish dialog
		['name' => 'api#listTargets',  'url' => '/api/v1/targets',          'verb' => 'GET'],
		// Metadata schema for a target (common fields + per-target extras)
		['name' => 'api#getSchema',    'url' => '/api/v1/targets/{target}/schema', 'verb' => 'GET'],
		// Start publishing: validates, stores metadata, returns the auth/redirect step
		['name' => 'api#begin',        'url' => '/api/v1/publish',          'verb' => 'POST'],
		// Admin: per-target credentials
		['name' => 'api#getConfig',    'url' => '/api/v1/config',           'verb' => 'GET'],
		['name' => 'api#setConfig',    'url' => '/api/v1/config',           'verb' => 'POST'],
	],
	'routes' => [
		// OAuth redirect target (registered with each repository)
		['name' => 'oauth#callback',    'url' => '/oauth/{target}/callback',   'verb' => 'GET'],
		// Progress page shown in the popup; kicks off the upload and shows result
		['name' => 'publish#progress',  'url' => '/publish/{target}/progress', 'verb' => 'GET'],
		// Performs the upload and returns JSON; called by the progress page
		['name' => 'publish#execute',   'url' => '/publish/{target}/run',      'verb' => 'GET'],
	],
];
