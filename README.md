<img src="https://agentisend.com/brand/lockup-horizontal-light.png#gh-light-mode-only" alt="AgentiSend" width="200" />
<img src="https://agentisend.com/brand/lockup-horizontal-dark.png#gh-dark-mode-only" alt="AgentiSend" width="200" />

# agentisend-php

Generated from `openapi.json`. Do not edit `src/Client.php` — run `pnpm sdk:generate`.

```php
$client = new AgentiSend\Client(); // reads AGENTISEND_API_KEY
$sent = $client->postEmails([
    'from' => 'Acme <hello@acme.com>', 'to' => ['you@example.com'],
    'subject' => 'Hi', 'text' => 'Hello',
]);
```

Install with Composer:

```
composer require agentisend/agentisend-php
```

No dependencies beyond curl and json, which every PHP install has. Laravel
users can wrap it; nothing here assumes a framework.

Refusals throw `AgentiSend\ApiError` with `code`, `fix`, `docsUrl`, `requestId`
and `retryAfterSeconds`. `retryable()` returns the API's own `retryable` field,
so a refusal the API marks as not worth retrying reads false; when the body has
none, it is true for 429 and 5xx.

## Idempotency-Key

Every mutating operation takes an optional trailing `$idempotencyKey`. Pass
one on a call you might retry — a timed-out send, a queue worker that redelivers —
so a repeat with the same key is applied once, not twice:

```php
$key = bin2hex(random_bytes(16));
$sent = $client->postEmails([
    'from' => 'Acme <hello@acme.com>', 'to' => ['you@example.com'],
    'subject' => 'Hi', 'text' => 'Hello',
], $key);
```
