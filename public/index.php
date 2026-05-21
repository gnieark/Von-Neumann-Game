<?php

declare(strict_types=1);

use VonNeumannGame\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = (new AppFactory(dirname(__DIR__)))->apiKernel();
$headers = function_exists('getallheaders') ? getallheaders() : [];
$path = $_SERVER['REQUEST_URI'] ?? '/';
$body = file_get_contents('php://input') ?: '';
$response = $kernel->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $headers, $body);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}

echo json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
