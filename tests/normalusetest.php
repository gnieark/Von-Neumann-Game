<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php tests/normalusetest.php <api_url> <username> <password>\n");
    fwrite(STDERR, "Example: php tests/normalusetest.php http://localhost:8000 remi secret\n");
    exit(1);
}

$apiUrl = rtrim($argv[1], '/');
$username = $argv[2];
$password = $argv[3];

function requestJson(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }

    $responseBody = file_get_contents($url, false, stream_context_create($options));
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 000 No response';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);

    return [
        'status' => isset($matches[1]) ? (int) $matches[1] : 0,
        'body' => $responseBody === false ? '' : $responseBody,
    ];
}

function printResponse(string $title, array $response): void
{
    echo "\n=== {$title} ===\n";
    echo "HTTP {$response['status']}\n";

    $decoded = json_decode((string) $response['body'], true);
    if (is_array($decoded)) {
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo $response['body'] . "\n";
}

$session = requestJson('POST', $apiUrl . '/api/session', [
    'username' => $username,
    'password' => $password,
]);
printResponse('POST /api/session', $session);

$sessionData = json_decode((string) $session['body'], true);
$token = is_array($sessionData) && isset($sessionData['token']) ? (string) $sessionData['token'] : null;

if ($session['status'] !== 200 || $token === null) {
    fwrite(STDERR, "\nUnable to obtain a token. Stopping normal use scenario.\n");
    exit(1);
}

$probe = requestJson('GET', $apiUrl . '/api/probe', null, $token);
printResponse('GET /api/probe', $probe);

$sector = requestJson('GET', $apiUrl . '/api/probe/sector', null, $token);
printResponse('GET /api/probe/sector', $sector);
