<?php
// The smoke program the M5.32 pin runs: send one email through the generated
// client against a real API with the fake transport, and print one line of
// JSON. A script rather than a test framework, so the pin needs only `php`.

declare(strict_types=1);

require __DIR__ . '/../src/Client.php';

use AgentiSend\ApiError;
use AgentiSend\Client;

$client = new Client();

$sent = $client->postEmails([
    'from' => getenv('SMOKE_FROM'),
    'to' => [getenv('SMOKE_TO')],
    'subject' => 'generated php sdk',
    'text' => 'sent through the generated client',
]);

$fetched = $client->getEmailsById((string) $sent['id']);

// A refusal must arrive as the documented envelope, not a bare status.
$refusal = [];
try {
    $client->postEmails([
        'from' => getenv('SMOKE_FROM'),
        'to' => ['not-an-address'],
        'subject' => 'should be refused',
        'text' => 'x',
    ]);
} catch (ApiError $error) {
    $refusal = [
        'code' => $error->code,
        'fix' => $error->fix,
        'retryable' => $error->retryable(),
    ];
}

echo json_encode([
    'id' => $sent['id'],
    'status' => $fetched['status'],
    'refusal' => $refusal,
]), PHP_EOL;
