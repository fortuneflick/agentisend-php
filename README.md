# AgentiSend — PHP SDK

AgentiSend is a transactional email API for AI agents and the products they run inside: verify a domain, create a key with a budget, send over REST or MCP, and read a log that says what happened to every message.

Generated from `openapi.json`. No Composer dependencies: it uses curl, which every PHP install has. Laravel users can wrap it; nothing here assumes a framework.

## Install

Not on Packagist yet. Clone this repository, or point Composer at it:

```bash
composer config repositories.agentisend vcs https://github.com/fortuneflick/agentisend-php
composer require agentisend/agentisend-php:dev-main
```

The client is one file with no dependencies, so `require 'src/Client.php';` also works.

## Send

```php
$client = new AgentiSend\Client(); // reads AGENTISEND_API_KEY
$sent = $client->postEmails([
    'from' => 'receipts@yourdomain.com',
    'to' => ['customer@example.com'],
    'subject' => 'Your receipt',
    'text' => 'Thanks. The details are in your account.',
]);
echo $sent['id'];
```

Every operation in the API has a method here; the name is the verb plus the path (`POST /emails/{id}/cancel` → `postEmailsByIdCancel`), so an endpoint that exists is callable and one that does not, is not.

Refusals throw `AgentiSend\ApiError` with `code`, `fix`, `docsUrl`, `requestId` and `retryAfterSeconds`. `retryable()` is true only for 429 and 5xx.

`src/Client.php` is generated. Do not edit it here; open an issue instead.

## Links

- Docs: <https://agentisend.com/docs>
- API contract: <https://agentisend.com/openapi.json>
- MCP endpoint: `https://api.agentisend.com/mcp` (bearer API key or OAuth 2.1; stdio launcher at [agentisend-mcp-server](https://github.com/fortuneflick/agentisend-mcp-server))
- [AGENTS.md](AGENTS.md) — the short version, for an agent doing this without a person.

Problems: hello@agentisend.com. Licensed MIT.
