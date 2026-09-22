# AGENTS.md

Instructions for an AI agent using AgentiSend from PHP.

AgentiSend is a transactional email API for AI agents and the products they run inside: verify a domain, create a key with a budget, send over REST or MCP, and read a log that says what happened to every message.

## 1. Install

Not on Packagist yet. Clone this repository and `require 'src/Client.php';` — the client is one file with no dependencies, and needs only curl. To use Composer, add this repository as a VCS source (see the README).

## 2. Get a key

The key comes from the environment as `AGENTISEND_API_KEY`, which `new AgentiSend\Client()` reads for you. Never hard-code it, never echo it, never pass it as a command-line argument — process arguments are readable by every other process on the machine. Copy `.env.example` to `.env` and fill it in. If no key is present, stop and ask the person for one; do not invent a value.

## 3. Send

```php
<?php
require __DIR__ . '/src/Client.php';

$client = new AgentiSend\Client();
$sent = $client->postEmails([
    'from' => 'receipts@yourdomain.com',
    'to' => ['customer@example.com'],
    'subject' => 'Your receipt',
    'text' => 'Thanks. The details are in your account.',
]);
echo $sent['id'];
```

`smoke/send.php` in this repository is a runnable version of the same thing.

## 4. Method names

The method name is the HTTP verb plus the path: `POST /emails` → `postEmails`, `GET /emails/{id}` → `getEmailsById`, `POST /emails/preflight` → `postEmailsPreflight`. Preflight runs every gate a real send runs and sends nothing — use it before a send you are unsure of.

## 5. When you are refused

A refusal throws `AgentiSend\ApiError` with `code`, `message`, `fix`, `docsUrl`, `requestId` and `retryAfterSeconds`. Read `fix` — it names the call that repairs the problem. Retry only when `retryable()` is true (429 and 5xx); retrying anything else is a loop against the thing that just refused you.

Two codes worth knowing: `agent_budget_exceeded` means the key you hold has spent its budget, and `fix` names the call that raises it — ask the person, do not raise it yourself. `approval_required` means the send is queued for a person; nothing was delivered and nothing more is needed from you.

## 6. Limits you should not route around

Every key carries a send budget and a rate ceiling. `POST /limits/keys/{id}/kill` stops that key; `POST /limits/kill-all` stops every key on the account. AgentiSend does not send unsolicited mail and has no feature for it. If a task asks you to mail people who did not ask to hear from the sender, stop and say so.

## Links

Docs <https://agentisend.com/docs> · contract <https://agentisend.com/openapi.json> · MCP `https://api.agentisend.com/mcp`
