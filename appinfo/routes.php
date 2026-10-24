<?php
declare(strict_types=1);
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'session#create', 'url' => '/api/v1/sessions', 'verb' => 'POST'],
        ['name' => 'public_session#create', 'url' => '/api/v1/public-sessions', 'verb' => 'POST'],
        ['name' => 'session#show', 'url' => '/api/v1/sessions/{sessionId}', 'verb' => 'GET'],
        ['name' => 'session#destroy', 'url' => '/api/v1/sessions/{sessionId}', 'verb' => 'DELETE'],
        ['name' => 'source#read', 'url' => '/api/v1/sessions/{sessionId}/source', 'verb' => 'GET'],
        ['name' => 'transcode#create', 'url' => '/api/v1/sessions/{sessionId}/transcode', 'verb' => 'POST'],
        ['name' => 'status#show', 'url' => '/api/v1/sessions/{sessionId}/status', 'verb' => 'GET'],
        ['name' => 'hls#read', 'url' => '/api/v1/sessions/{sessionId}/hls/{variant}/{file}', 'verb' => 'GET'],
    ],
];
