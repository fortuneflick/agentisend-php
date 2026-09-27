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
users can wrap it; nothing here assumes a framework. Until the Packagist page
is live, add the repository by hand:

```
composer config repositories.agentisend vcs https://github.com/fortuneflick/agentisend-php
composer require agentisend/agentisend-php:dev-main
```

Refusals throw `AgentiSend\ApiError` with `code`, `fix`, `docsUrl`, `requestId`
and `retryAfterSeconds`. `retryable()` is true only for 429 and 5xx.
