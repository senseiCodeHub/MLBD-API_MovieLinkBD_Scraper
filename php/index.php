<?php
declare(strict_types=1);

/**
 * MLBD Scraper — single entry point for the UI and the JSON API.
 *
 * Deploy this folder as the site root (Webuzo/cPanel ``public_html``) or point
 * the document root at it. Every request is routed from here; ``.htaccess``
 * rewrites unknown paths to this file, and ``?r=/path`` works without rewrites.
 */

require __DIR__ . '/app/bootstrap.php';

use Mlbd\Http\Api;
use Mlbd\Http\Web;

$path = request_path();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    exit;
}

// API routes are served first; everything else is a page.
if (Api::handle($path, $method)) {
    exit;
}

if (Web::handle($path)) {
    exit;
}

http_response_code(404);
if (wants_json()) {
    json_response([
        'ok'         => false,
        'data'       => null,
        'error'      => 'Not found: ' . $path,
        'error_type' => 'NotFound',
    ], 404);
}

Web::render('notfound', ['path' => $path], 'Not found');
