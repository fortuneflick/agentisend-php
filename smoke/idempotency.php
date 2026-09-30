<?php
// The smoke program the RESENDCOMPAT-10 pin runs: send the same email twice
// with the same Idempotency-Key (the trailing $idempotencyKey argument) and
// print both ids, so the pin can assert the second call replays the first
// rather than sending twice.

declare(strict_types=1);

require __DIR__ . '/../src/Client.php';

use AgentiSend\Client;

$client = new Client();
$key = (string) getenv('SMOKE_IDEMPOTENCY_KEY');
$body = [
    'from' => getenv('SMOKE_FROM'),
    'to' => [getenv('SMOKE_TO')],
    'subject' => 'generated php sdk idempotency',
    'text' => 'sent through the generated client',
];

$first = $client->postEmails($body, $key);
$second = $client->postEmails($body, $key);

echo json_encode([
    'firstId' => $first['id'],
    'secondId' => $second['id'],
]), PHP_EOL;
