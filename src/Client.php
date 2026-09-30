<?php
// Code generated from openapi.json. DO NOT EDIT.
//
// Regenerate with: pnpm sdk:generate
//
// Every method maps to exactly one operation in the spec, so an endpoint that
// exists is callable here and one that does not, is not.

declare(strict_types=1);

namespace AgentiSend;

/**
 * The documented error envelope. `fix` says what to do about it — a caller
 * that logs only the message has thrown away the useful half.
 */
class ApiError extends \RuntimeException
{
    public int $status;
    /**
     * The catalogue code, e.g. `validation_error`. Also returned by getCode().
     *
     * Untyped on purpose: \Exception::$code is untyped in the engine — so that
     * PDOException can hold a string SQLSTATE — and PHP forbids a subclass
     * adding a type to an untyped inherited property. Declaring it `string`
     * here is a fatal error on load, on every PHP since 7.4.
     *
     * @var string
     */
    public $code;
    public string $fix;
    public string $docsUrl;
    public ?string $requestId;
    public ?int $retryAfterSeconds;
    public bool $retryable;

    public function __construct(
        int $status,
        string $code,
        string $message,
        string $fix,
        string $docsUrl = '',
        ?string $requestId = null,
        ?int $retryAfterSeconds = null,
        ?bool $retryable = null
    ) {
        parent::__construct(sprintf('agentisend [%s]: %s — %s', $code, $message, $fix));
        $this->status = $status;
        $this->code = $code;
        $this->fix = $fix;
        $this->docsUrl = $docsUrl;
        $this->requestId = $requestId;
        $this->retryAfterSeconds = $retryAfterSeconds;
        $this->retryable = $retryable ?? ($status === 429 || $status >= 500);
    }

    /**
     * Whether repeating the identical request could work later. Reads the
     * body when the API sent `retryable`, so a 429 that waiting cannot cure
     * is not retried.
     */
    public function retryable(): bool
    {
        return $this->retryable;
    }
}

class Client
{
    /** Sent in the user agent, so support can tell one SDK version from another. */
    public const SDK_VERSION = '0.1.0';

    private string $apiKey;
    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $key = $apiKey ?? getenv('AGENTISEND_API_KEY') ?: '';
        if ($key === '') {
            throw new \InvalidArgumentException(
                'agentisend: no API key. Pass one, or set AGENTISEND_API_KEY.'
            );
        }
        $this->apiKey = $key;
        $url = $baseUrl ?? getenv('AGENTISEND_BASE_URL') ?: 'https://api.agentisend.com';
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * Performs one request. Every generated method calls it.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>|null $query
     * @param string|null $idempotencyKey Sent as the idempotency-key header,
     *     so a retried call with the same key is applied once. Pass it on
     *     any non-GET call.
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?array $query = null,
        ?string $idempotencyKey = null
    ): array {
        $endpoint = $this->baseUrl . $path;
        if ($query !== null && $query !== []) {
            $endpoint .= '?' . http_build_query(array_filter($query, static fn ($v) => $v !== '' && $v !== null));
        }

        $headers = [
            'authorization: Bearer ' . $this->apiKey,
            'accept: application/json',
            'user-agent: agentisend-php/' . self::SDK_VERSION . ' php/' . PHP_VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'idempotency-key: ' . $idempotencyKey;
        }
        $handle = curl_init($endpoint);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_HEADER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        if ($body !== null) {
            $headers[] = 'content-type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('agentisend: request failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $payload = substr((string) $raw, $headerSize);

        $parsed = $payload === '' ? [] : json_decode($payload, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException(sprintf('agentisend: response was not JSON (%d): %s', $status, $payload));
        }

        if ($status >= 400) {
            $envelope = is_array($parsed['error'] ?? null) ? $parsed['error'] : [];
            $retryable = array_key_exists('retryable', $envelope) ? (bool) $envelope['retryable'] : null;
            $wait = self::intHeader($rawHeaders, 'retry-after');
            if ($wait === null && isset($envelope['retry_after_seconds']) && is_numeric($envelope['retry_after_seconds'])) {
                $wait = (int) $envelope['retry_after_seconds'];
            }
            throw new ApiError(
                $status,
                (string) ($envelope['code'] ?? 'unexpected_error'),
                (string) ($envelope['message'] ?? 'The request failed.'),
                (string) ($envelope['fix'] ?? ''),
                (string) ($envelope['docs_url'] ?? ''),
                self::headerValue($rawHeaders, 'x-request-id'),
                $wait,
                $retryable
            );
        }

        return $parsed;
    }

    private static function headerValue(string $rawHeaders, string $name): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strtolower(trim($parts[0])) === $name) {
                return trim($parts[1]);
            }
        }
        return null;
    }

    private static function intHeader(string $rawHeaders, string $name): ?int
    {
        $value = self::headerValue($rawHeaders, $name);
        return $value === null || !is_numeric($value) ? null : (int) $value;
    }

    /**
     * Close this account: keys stop now.
     *
     * DELETE /account
     *
     * @return array<string,mixed>
     */
    public function deleteAccount(?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', '/account', null, null, $idempotencyKey);
    }

    /**
     * Revoke an API key immediately.
     *
     * DELETE /api-keys/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteApiKeysById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/api-keys/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Alias of DELETE /contacts/{id}.
     *
     * DELETE /audiences/{audienceId}/contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteAudiencesByAudienceIdContactsById(string $audienceId, string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{audienceId}', '{id}'], [$audienceId, $id], '/audiences/{audienceId}/contacts/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete one contact and its properties.
     *
     * DELETE /contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteContactsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/contacts/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Not available.
     *
     * DELETE /contacts/{id}/segments/{segmentId}
     *
     * @return array<string,mixed>
     */
    public function deleteContactsByIdSegmentsBySegmentId(string $id, string $segmentId, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}', '{segmentId}'], [$id, $segmentId], '/contacts/{id}/segments/{segmentId}'), null, null, $idempotencyKey);
    }

    /**
     * Release a dedicated IP.
     *
     * DELETE /dedicated-ips/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteDedicatedIpsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/dedicated-ips/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Remove a domain and its records.
     *
     * DELETE /domains/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteDomainsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/domains/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete one email you sent.
     *
     * DELETE /emails/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteEmailsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/emails/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Not open yet: receiving mail is not open to customers.
     *
     * DELETE /emails/receiving/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteEmailsReceivingById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/emails/receiving/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Forget an event definition.
     *
     * DELETE /events/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteEventsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/events/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Revoke one assistant connection.
     *
     * DELETE /oauth/grants/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteOauthGrantsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/oauth/grants/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete a segment.
     *
     * DELETE /segments/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteSegmentsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/segments/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Remove one suppression row.
     *
     * DELETE /suppressions/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteSuppressionsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/suppressions/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Cancel a pending invitation.
     *
     * DELETE /team/invites/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTeamInvitesById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/team/invites/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Remove a member.
     *
     * DELETE /team/members/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTeamMembersById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/team/members/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete a template and its versions.
     *
     * DELETE /templates/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTemplatesById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/templates/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete a topic and every recorded answer about it.
     *
     * DELETE /topics/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTopicsById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/topics/{id}'), null, null, $idempotencyKey);
    }

    /**
     * Delete an endpoint and its queued deliveries.
     *
     * DELETE /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteWebhooksById(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/webhooks/{id}'), null, null, $idempotencyKey);
    }

    /**
     * This account: lifecycle status, the reason it is in that state, and when it was created.
     *
     * GET /account
     *
     * @return array<string,mixed>
     */
    public function getAccount(): array
    {
        return $this->request('GET', '/account', null, null, null);
    }

    /**
     * A zip of this account's data, with a row count for every file in manifest.json.
     *
     * GET /account/export
     *
     * @return array<string,mixed>
     */
    public function getAccountExport(): array
    {
        return $this->request('GET', '/account/export', null, null, null);
    }

    /**
     * Every held agent action, newest first — nothing waits invisibly.
     *
     * GET /agent-actions
     *
     * @return array<string,mixed>
     */
    public function getAgentActions(?array $query = null): array
    {
        return $this->request('GET', '/agent-actions', null, $query, null);
    }

    /**
     * List API keys with 30-day request counts.
     *
     * GET /api-keys
     *
     * @return array<string,mixed>
     */
    public function getApiKeys(?array $query = null): array
    {
        return $this->request('GET', '/api-keys', null, $query, null);
    }

    /**
     * Alias of GET /contacts.
     *
     * GET /audiences/{audienceId}/contacts
     *
     * @return array<string,mixed>
     */
    public function getAudiencesByAudienceIdContacts(string $audienceId, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{audienceId}'], [$audienceId], '/audiences/{audienceId}/contacts'), null, $query, null);
    }

    /**
     * Alias of GET /contacts/{id}.
     *
     * GET /audiences/{audienceId}/contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function getAudiencesByAudienceIdContactsById(string $audienceId, string $id): array
    {
        return $this->request('GET', str_replace(['{audienceId}', '{id}'], [$audienceId, $id], '/audiences/{audienceId}/contacts/{id}'), null, null, null);
    }

    /**
     * Changes made on this account (keys, limits, kill switch, domains, webhooks, suppressions, contacts, segments, topics, templates, broadcasts, automations,…
     *
     * GET /audit-log
     *
     * @return array<string,mixed>
     */
    public function getAuditLog(?array $query = null): array
    {
        return $this->request('GET', '/audit-log', null, $query, null);
    }

    /**
     * Every automation on the account.
     *
     * GET /automations
     *
     * @return array<string,mixed>
     */
    public function getAutomations(?array $query = null): array
    {
        return $this->request('GET', '/automations', null, $query, null);
    }

    /**
     * One automation.
     *
     * GET /automations/{id}
     *
     * @return array<string,mixed>
     */
    public function getAutomationsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}'), null, null, null);
    }

    /**
     * Every time this automation fired, newest first — the answer to "did it run?", which the engine used to throw away.
     *
     * GET /automations/{id}/runs
     *
     * @return array<string,mixed>
     */
    public function getAutomationsByIdRuns(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}/runs'), null, $query, null);
    }

    /**
     * One run with every step: what it did, what it returned, and the sentence for the ones that failed.
     *
     * GET /automations/{id}/runs/{run_id}
     *
     * @return array<string,mixed>
     */
    public function getAutomationsByIdRunsByRunId(string $id, string $runid): array
    {
        return $this->request('GET', str_replace(['{id}', '{run_id}'], [$id, $runid], '/automations/{id}/runs/{run_id}'), null, null, null);
    }

    /**
     * Every immutable version, oldest first.
     *
     * GET /automations/{id}/versions
     *
     * @return array<string,mixed>
     */
    public function getAutomationsByIdVersions(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}/versions'), null, $query, null);
    }

    /**
     * Plan, usage against each published limit, and what the next tier changes.
     *
     * GET /billing
     *
     * @return array<string,mixed>
     */
    public function getBilling(): array
    {
        return $this->request('GET', '/billing', null, null, null);
    }

    /**
     * The plan in force (Free until one is bought), the plans, volumes and terms on offer, and what the signed-in person may buy.
     *
     * GET /billing/plan
     *
     * @return array<string,mixed>
     */
    public function getBillingPlan(): array
    {
        return $this->request('GET', '/billing/plan', null, null, null);
    }

    /**
     * Console: the signed-in account’s plan and subscription state.
     *
     * GET /billing/subscription
     *
     * @return array<string,mixed>
     */
    public function getBillingSubscription(): array
    {
        return $this->request('GET', '/billing/subscription', null, null, null);
    }

    /**
     * Every broadcast, newest first — archived included.
     *
     * GET /broadcasts
     *
     * @return array<string,mixed>
     */
    public function getBroadcasts(?array $query = null): array
    {
        return $this->request('GET', '/broadcasts', null, $query, null);
    }

    /**
     * One broadcast.
     *
     * GET /broadcasts/{id}
     *
     * @return array<string,mixed>
     */
    public function getBroadcastsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}'), null, null, null);
    }

    /**
     * Per-member delivery state for this broadcast.
     *
     * GET /broadcasts/{id}/messages
     *
     * @return array<string,mixed>
     */
    public function getBroadcastsByIdMessages(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}/messages'), null, $query, null);
    }

    /**
     * Render this broadcast the way a recipient would see it.
     *
     * GET /broadcasts/{id}/preview
     *
     * @return array<string,mixed>
     */
    public function getBroadcastsByIdPreview(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}/preview'), null, null, null);
    }

    /**
     * Sent, delivered, opened, clicked, bounced, complained and unsubscribed totals for one broadcast.
     *
     * GET /broadcasts/{id}/stats
     *
     * @return array<string,mixed>
     */
    public function getBroadcastsByIdStats(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}/stats'), null, null, null);
    }

    /**
     * Property names and types already stored on this account.
     *
     * GET /contact-properties
     *
     * @return array<string,mixed>
     */
    public function getContactProperties(): array
    {
        return $this->request('GET', '/contact-properties', null, null, null);
    }

    /**
     * One stored property name and its type.
     *
     * GET /contact-properties/{id}
     *
     * @return array<string,mixed>
     */
    public function getContactPropertiesById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/contact-properties/{id}'), null, null, null);
    }

    /**
     * List contacts, newest first.
     *
     * GET /contacts
     *
     * @return array<string,mixed>
     */
    public function getContacts(?array $query = null): array
    {
        return $this->request('GET', '/contacts', null, $query, null);
    }

    /**
     * One contact with its properties.
     *
     * GET /contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function getContactsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/contacts/{id}'), null, null, null);
    }

    /**
     * Segments whose rules include this contact.
     *
     * GET /contacts/{id}/segments
     *
     * @return array<string,mixed>
     */
    public function getContactsByIdSegments(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/contacts/{id}/segments'), null, null, null);
    }

    /**
     * What this contact has said about every topic.
     *
     * GET /contacts/{id}/topics
     *
     * @return array<string,mixed>
     */
    public function getContactsByIdTopics(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/contacts/{id}/topics'), null, $query, null);
    }

    /**
     * This account dedicated IPs with live warmup percentages.
     *
     * GET /dedicated-ips
     *
     * @return array<string,mixed>
     */
    public function getDedicatedIps(?array $query = null): array
    {
        return $this->request('GET', '/dedicated-ips', null, $query, null);
    }

    /**
     * The PUBLISHED warmup curve — exactly how volume moves and when.
     *
     * GET /dedicated-ips/ramp
     *
     * @return array<string,mixed>
     */
    public function getDedicatedIpsRamp(): array
    {
        return $this->request('GET', '/dedicated-ips/ramp', null, null, null);
    }

    /**
     * Which route this message would take RIGHT NOW — reproducible, the same math the send path uses.
     *
     * GET /dedicated-ips/route-decision/{messageId}
     *
     * @return array<string,mixed>
     */
    public function getDedicatedIpsRouteDecisionByMessageId(string $messageId): array
    {
        return $this->request('GET', str_replace(['{messageId}'], [$messageId], '/dedicated-ips/route-decision/{messageId}'), null, null, null);
    }

    /**
     * Authentication reports for verified domains.
     *
     * GET /deliverability/dmarc
     *
     * @return array<string,mixed>
     */
    public function getDeliverabilityDmarc(?array $query = null): array
    {
        return $this->request('GET', '/deliverability/dmarc', null, $query, null);
    }

    /**
     * Every sending domain with its standing over the window, worst first — the failing domain is the first row, not one you have to find.
     *
     * GET /deliverability/domains
     *
     * @return array<string,mixed>
     */
    public function getDeliverabilityDomains(?array $query = null): array
    {
        return $this->request('GET', '/deliverability/domains', null, $query, null);
    }

    /**
     * Per-domain reputation: live rates over the rolling window, daily snapshots, the thresholds those rates are judged against, the bounce breakdown by class with…
     *
     * GET /deliverability/domains/{id}
     *
     * @return array<string,mixed>
     */
    public function getDeliverabilityDomainsById(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/deliverability/domains/{id}'), null, $query, null);
    }

    /**
     * List domains.
     *
     * GET /domains
     *
     * @return array<string,mixed>
     */
    public function getDomains(?array $query = null): array
    {
        return $this->request('GET', '/domains', null, $query, null);
    }

    /**
     * One domain, with the DNS to publish and what each record last resolved to.
     *
     * GET /domains/{id}
     *
     * @return array<string,mixed>
     */
    public function getDomainsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}'), null, null, null);
    }

    /**
     * Alias of GET /domains/:id/setup.
     *
     * GET /domains/{id}/connect
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdConnect(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/connect'), null, null, null);
    }

    /**
     * Newest-first timeline for one domain.
     *
     * GET /domains/{id}/events
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdEvents(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/events'), null, $query, null);
    }

    /**
     * One-shot DNS setup: zone file, copy-paste commands, provider detection, and every record with host, zone, value_strings.
     *
     * GET /domains/{id}/setup
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdSetup(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/setup'), null, null, null);
    }

    /**
     * Long-poll a domain until it is verified or failed, or until timeout (0–25 seconds).
     *
     * GET /domains/{id}/wait
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdWait(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/wait'), null, $query, null);
    }

    /**
     * List messages.
     *
     * GET /emails
     *
     * @return array<string,mixed>
     */
    public function getEmails(?array $query = null): array
    {
        return $this->request('GET', '/emails', null, $query, null);
    }

    /**
     * Fetch one message with its current status and last event.
     *
     * GET /emails/{id}
     *
     * @return array<string,mixed>
     */
    public function getEmailsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}'), null, null, null);
    }

    /**
     * What this email carried.
     *
     * GET /emails/{id}/attachments
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdAttachments(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/attachments'), null, $query, null);
    }

    /**
     * The bytes of one attachment, exactly as they were sent.
     *
     * GET /emails/{id}/attachments/{aid}
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdAttachmentsByAid(string $id, string $aid): array
    {
        return $this->request('GET', str_replace(['{id}', '{aid}'], [$id, $aid], '/emails/{id}/attachments/{aid}'), null, null, null);
    }

    /**
     * Download this email as.eml.
     *
     * GET /emails/{id}/eml
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdEml(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/eml'), null, null, null);
    }

    /**
     * Every event recorded for one message, oldest first, with the provider detail.
     *
     * GET /emails/{id}/events
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdEvents(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/events'), null, $query, null);
    }

    /**
     * What happened to this email, the evidence, and the exact calls that fix it — machine-readable remediation.
     *
     * GET /emails/{id}/explain
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdExplain(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/explain'), null, null, null);
    }

    /**
     * The exact RFC 5322 source of this email — what Resend never shows you.
     *
     * GET /emails/{id}/mime
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdMime(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/mime'), null, null, null);
    }

    /**
     * The message log as CSV — yours, take it with you.
     *
     * GET /emails/export.csv
     *
     * @return array<string,mixed>
     */
    public function getEmailsExportCsv(?array $query = null): array
    {
        return $this->request('GET', '/emails/export.csv', null, $query, null);
    }

    /**
     * Alias of GET /metrics.
     *
     * GET /emails/metrics
     *
     * @return array<string,mixed>
     */
    public function getEmailsMetrics(?array $query = null): array
    {
        return $this->request('GET', '/emails/metrics', null, $query, null);
    }

    /**
     * Not open yet: mail received at this account’s domains, newest first.
     *
     * GET /emails/receiving
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceiving(?array $query = null): array
    {
        return $this->request('GET', '/emails/receiving', null, $query, null);
    }

    /**
     * Not open yet: one received email: headers, text and HTML bodies as data, and the attachments it carried.
     *
     * GET /emails/receiving/{id}
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}'), null, null, null);
    }

    /**
     * Not open yet: what this received email carried.
     *
     * GET /emails/receiving/{id}/attachments
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdAttachments(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}/attachments'), null, $query, null);
    }

    /**
     * Not open yet: the bytes of one received attachment, as an inert download — always octet-stream, never the sender’s declared type.
     *
     * GET /emails/receiving/{id}/attachments/{aid}
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdAttachmentsByAid(string $id, string $aid): array
    {
        return $this->request('GET', str_replace(['{id}', '{aid}'], [$id, $aid], '/emails/receiving/{id}/attachments/{aid}'), null, null, null);
    }

    /**
     * Not open yet: the stored RFC 5322 source, byte for byte.
     *
     * GET /emails/receiving/{id}/raw
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdRaw(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}/raw'), null, null, null);
    }

    /**
     * Not open yet: received mail grouped into threads by Message-ID / In-Reply-To / References, newest thread first.
     *
     * GET /emails/receiving/threads
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingThreads(?array $query = null): array
    {
        return $this->request('GET', '/emails/receiving/threads', null, $query, null);
    }

    /**
     * Every custom event this account has declared, with its typed schema and when it was last seen.
     *
     * GET /events
     *
     * @return array<string,mixed>
     */
    public function getEvents(?array $query = null): array
    {
        return $this->request('GET', '/events', null, $query, null);
    }

    /**
     * One event definition.
     *
     * GET /events/{id}
     *
     * @return array<string,mixed>
     */
    public function getEventsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/events/{id}'), null, null, null);
    }

    /**
     * Liveness probe.
     *
     * GET /health
     *
     * @return array<string,mixed>
     */
    public function getHealth(): array
    {
        return $this->request('GET', '/health', null, null, null);
    }

    /**
     * The fleet: every key with its budget, ceiling, consumption, identity and current loop state.
     *
     * GET /limits/keys
     *
     * @return array<string,mixed>
     */
    public function getLimitsKeys(?array $query = null): array
    {
        return $this->request('GET', '/limits/keys', null, $query, null);
    }

    /**
     * Budget, ceiling and current consumption for one key.
     *
     * GET /limits/keys/{id}
     *
     * @return array<string,mixed>
     */
    public function getLimitsKeysById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/limits/keys/{id}'), null, null, null);
    }

    /**
     * Every API request this account made, with method, route, status and any refusal.
     *
     * GET /logs
     *
     * @return array<string,mixed>
     */
    public function getLogs(?array $query = null): array
    {
        return $this->request('GET', '/logs', null, $query, null);
    }

    /**
     * One request, by log id.
     *
     * GET /logs/{id}
     *
     * @return array<string,mixed>
     */
    public function getLogsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/logs/{id}'), null, null, null);
    }

    /**
     * Send/delivery/engagement KPIs with a daily series, computed from the event spine over a window.
     *
     * GET /metrics
     *
     * @return array<string,mixed>
     */
    public function getMetrics(?array $query = null): array
    {
        return $this->request('GET', '/metrics', null, $query, null);
    }

    /**
     * Conditions this account should know about.
     *
     * GET /notifications
     *
     * @return array<string,mixed>
     */
    public function getNotifications(?array $query = null): array
    {
        return $this->request('GET', '/notifications', null, $query, null);
    }

    /**
     * Which channels each notification type uses.
     *
     * GET /notifications/preferences
     *
     * @return array<string,mixed>
     */
    public function getNotificationsPreferences(): array
    {
        return $this->request('GET', '/notifications/preferences', null, null, null);
    }

    /**
     * Every assistant connection on this account, newest first.
     *
     * GET /oauth/grants
     *
     * @return array<string,mixed>
     */
    public function getOauthGrants(?array $query = null): array
    {
        return $this->request('GET', '/oauth/grants', null, $query, null);
    }

    /**
     * OpenAPI 3.1 document generated from the same schemas that validate.
     *
     * GET /openapi.json
     *
     * @return array<string,mixed>
     */
    public function getOpenapiJson(): array
    {
        return $this->request('GET', '/openapi.json', null, null, null);
    }

    /**
     * Every segment on the account.
     *
     * GET /segments
     *
     * @return array<string,mixed>
     */
    public function getSegments(?array $query = null): array
    {
        return $this->request('GET', '/segments', null, $query, null);
    }

    /**
     * One segment.
     *
     * GET /segments/{id}
     *
     * @return array<string,mixed>
     */
    public function getSegmentsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/segments/{id}'), null, null, null);
    }

    /**
     * Contacts in this segment.
     *
     * GET /segments/{id}/contacts
     *
     * @return array<string,mixed>
     */
    public function getSegmentsByIdContacts(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/segments/{id}/contacts'), null, $query, null);
    }

    /**
     * The segment evaluated RIGHT NOW — live member ids, never a snapshot.
     *
     * GET /segments/{id}/members
     *
     * @return array<string,mixed>
     */
    public function getSegmentsByIdMembers(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/segments/{id}/members'), null, null, null);
    }

    /**
     * Public per-component health.
     *
     * GET /status
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        return $this->request('GET', '/status', null, null, null);
    }

    /**
     * Daily uptime per component for 90 days, in minutes.
     *
     * GET /status/history
     *
     * @return array<string,mixed>
     */
    public function getStatusHistory(?array $query = null): array
    {
        return $this->request('GET', '/status/history', null, $query, null);
    }

    /**
     * Support requests on this account, newest activity first.
     *
     * GET /support
     *
     * @return array<string,mixed>
     */
    public function getSupport(?array $query = null): array
    {
        return $this->request('GET', '/support', null, $query, null);
    }

    /**
     * Download an attachment on a request this account owns.
     *
     * GET /support/attachments/{id}/download
     *
     * @return array<string,mixed>
     */
    public function getSupportAttachmentsByIdDownload(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/support/attachments/{id}/download'), null, null, null);
    }

    /**
     * One support request and its public thread.
     *
     * GET /support/{ref}
     *
     * @return array<string,mixed>
     */
    public function getSupportByRef(string $ref): array
    {
        return $this->request('GET', str_replace(['{ref}'], [$ref], '/support/{ref}'), null, null, null);
    }

    /**
     * Topics, hours and attachment limits for opening a support request.
     *
     * GET /support/config
     *
     * @return array<string,mixed>
     */
    public function getSupportConfig(): array
    {
        return $this->request('GET', '/support/config', null, null, null);
    }

    /**
     * Suppressions recorded on this account, with a count of platform-wide rows and none of their addresses.
     *
     * GET /suppressions
     *
     * @return array<string,mixed>
     */
    public function getSuppressions(?array $query = null): array
    {
        return $this->request('GET', '/suppressions', null, $query, null);
    }

    /**
     * One suppression, by its id or by the address.
     *
     * GET /suppressions/{id}
     *
     * @return array<string,mixed>
     */
    public function getSuppressionsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/suppressions/{id}'), null, null, null);
    }

    /**
     * Invitations that have not been accepted, cancelled or expired.
     *
     * GET /team/invites
     *
     * @return array<string,mixed>
     */
    public function getTeamInvites(?array $query = null): array
    {
        return $this->request('GET', '/team/invites', null, $query, null);
    }

    /**
     * Your membership on this account: your role, and the seats the plan allows.
     *
     * GET /team/me
     *
     * @return array<string,mixed>
     */
    public function getTeamMe(): array
    {
        return $this->request('GET', '/team/me', null, null, null);
    }

    /**
     * Everyone on this account and the role each one holds.
     *
     * GET /team/members
     *
     * @return array<string,mixed>
     */
    public function getTeamMembers(?array $query = null): array
    {
        return $this->request('GET', '/team/members', null, $query, null);
    }

    /**
     * List this account templates, newest first.
     *
     * GET /templates
     *
     * @return array<string,mixed>
     */
    public function getTemplates(?array $query = null): array
    {
        return $this->request('GET', '/templates', null, $query, null);
    }

    /**
     * One template.
     *
     * GET /templates/{id}
     *
     * @return array<string,mixed>
     */
    public function getTemplatesById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}'), null, null, null);
    }

    /**
     * Structured diff between two versions, or between a version and the draft.
     *
     * GET /templates/{id}/diff
     *
     * @return array<string,mixed>
     */
    public function getTemplatesByIdDiff(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}/diff'), null, $query, null);
    }

    /**
     * Every immutable version, oldest first.
     *
     * GET /templates/{id}/versions
     *
     * @return array<string,mixed>
     */
    public function getTemplatesByIdVersions(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}/versions'), null, $query, null);
    }

    /**
     * One version.
     *
     * GET /templates/{id}/versions/{n}
     *
     * @return array<string,mixed>
     */
    public function getTemplatesByIdVersionsByN(string $id, string $n): array
    {
        return $this->request('GET', str_replace(['{id}', '{n}'], [$id, $n], '/templates/{id}/versions/{n}'), null, null, null);
    }

    /**
     * The DNS records a sending domain needs, and the exact records to publish.
     *
     * GET /tools/dns/{domain}
     *
     * @return array<string,mixed>
     */
    public function getToolsDnsByDomain(string $domain): array
    {
        return $this->request('GET', str_replace(['{domain}'], [$domain], '/tools/dns/{domain}'), null, null, null);
    }

    /**
     * Every subscription topic on the account, newest first.
     *
     * GET /topics
     *
     * @return array<string,mixed>
     */
    public function getTopics(?array $query = null): array
    {
        return $this->request('GET', '/topics', null, $query, null);
    }

    /**
     * One topic.
     *
     * GET /topics/{id}
     *
     * @return array<string,mixed>
     */
    public function getTopicsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/topics/{id}'), null, null, null);
    }

    /**
     * The remediation checklist ticks for this account.
     *
     * GET /trust/remediation
     *
     * @return array<string,mixed>
     */
    public function getTrustRemediation(): array
    {
        return $this->request('GET', '/trust/remediation', null, null, null);
    }

    /**
     * This account’s standing, machine-readable.
     *
     * GET /trust/standing
     *
     * @return array<string,mixed>
     */
    public function getTrustStanding(): array
    {
        return $this->request('GET', '/trust/standing', null, null, null);
    }

    /**
     * The published enforcement thresholds and the ladder they drive.
     *
     * GET /trust/thresholds
     *
     * @return array<string,mixed>
     */
    public function getTrustThresholds(): array
    {
        return $this->request('GET', '/trust/thresholds', null, null, null);
    }

    /**
     * This period usage, per key: budget, consumed, remaining, rate window, last used.
     *
     * GET /usage
     *
     * @return array<string,mixed>
     */
    public function getUsage(): array
    {
        return $this->request('GET', '/usage', null, null, null);
    }

    /**
     * List endpoints.
     *
     * GET /webhooks
     *
     * @return array<string,mixed>
     */
    public function getWebhooks(?array $query = null): array
    {
        return $this->request('GET', '/webhooks', null, $query, null);
    }

    /**
     * Fetch one endpoint.
     *
     * GET /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function getWebhooksById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}'), null, null, null);
    }

    /**
     * Deliveries this endpoint exhausted every retry on, with the payload to inspect.
     *
     * GET /webhooks/{id}/dead-letters
     *
     * @return array<string,mixed>
     */
    public function getWebhooksByIdDeadLetters(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}/dead-letters'), null, $query, null);
    }

    /**
     * Every delivery to this endpoint, with each attempt and what the receiver answered.
     *
     * GET /webhooks/{id}/deliveries
     *
     * @return array<string,mixed>
     */
    public function getWebhooksByIdDeliveries(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}/deliveries'), null, $query, null);
    }

    /**
     * Change account settings.
     *
     * PATCH /account
     *
     * @return array<string,mixed>
     */
    public function patchAccount(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', '/account', $body, null, $idempotencyKey);
    }

    /**
     * Console: the onboarding answers — your name, the company (which becomes the account name), the website, what you will send and roughly how much.
     *
     * PATCH /account/onboarding
     *
     * @return array<string,mixed>
     */
    public function patchAccountOnboarding(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', '/account/onboarding', $body, null, $idempotencyKey);
    }

    /**
     * Rename a key or change its domain scope and scopes.
     *
     * PATCH /api-keys/{id}
     *
     * @return array<string,mixed>
     */
    public function patchApiKeysById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/api-keys/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Alias of PATCH /contacts/{id}.
     *
     * PATCH /audiences/{audienceId}/contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function patchAudiencesByAudienceIdContactsById(string $audienceId, string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{audienceId}', '{id}'], [$audienceId, $id], '/audiences/{audienceId}/contacts/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Edit a draft or disabled automation.
     *
     * PATCH /automations/{id}
     *
     * @return array<string,mixed>
     */
    public function patchAutomationsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/automations/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Edit an unsent broadcast, or rename ANY one.
     *
     * PATCH /broadcasts/{id}
     *
     * @return array<string,mixed>
     */
    public function patchBroadcastsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/broadcasts/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Edit one contact by id or email.
     *
     * PATCH /contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function patchContactsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/contacts/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Record this contact’s answer for one or more topics.
     *
     * PATCH /contacts/{id}/topics
     *
     * @return array<string,mixed>
     */
    public function patchContactsByIdTopics(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/contacts/{id}/topics'), $body, null, $idempotencyKey);
    }

    /**
     * Turn click or open tracking on, or change the tracking subdomain.
     *
     * PATCH /domains/{id}
     *
     * @return array<string,mixed>
     */
    public function patchDomainsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/domains/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Move a scheduled email to a new time — the same act as POST /emails/:id/reschedule, under the verb a Resend integration already uses.
     *
     * PATCH /emails/{id}
     *
     * @return array<string,mixed>
     */
    public function patchEmailsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/emails/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Declare or re-declare an event’s fields.
     *
     * PATCH /events/{id}
     *
     * @return array<string,mixed>
     */
    public function patchEventsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/events/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Set or clear this key’s period budget and per-minute ceiling.
     *
     * PATCH /limits/keys/{id}
     *
     * @return array<string,mixed>
     */
    public function patchLimitsKeysById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/limits/keys/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Turn a notification type on or off per channel.
     *
     * PATCH /notifications/preferences
     *
     * @return array<string,mixed>
     */
    public function patchNotificationsPreferences(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', '/notifications/preferences', $body, null, $idempotencyKey);
    }

    /**
     * Rename or change the rules.
     *
     * PATCH /segments/{id}
     *
     * @return array<string,mixed>
     */
    public function patchSegmentsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/segments/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Change a member’s role.
     *
     * PATCH /team/members/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTeamMembersById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/team/members/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Edit the draft.
     *
     * PATCH /templates/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTemplatesById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/templates/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Rename a topic or change its default.
     *
     * PATCH /topics/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTopicsById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/topics/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Update url, subscribed events, or the disabled flag.
     *
     * PATCH /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function patchWebhooksById(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/webhooks/{id}'), $body, null, $idempotencyKey);
    }

    /**
     * Execute the held send through the normal accept path.
     *
     * POST /agent-actions/{id}/approve
     *
     * @return array<string,mixed>
     */
    public function postAgentActionsByIdApprove(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/agent-actions/{id}/approve'), null, null, $idempotencyKey);
    }

    /**
     * Refuse the held send.
     *
     * POST /agent-actions/{id}/reject
     *
     * @return array<string,mixed>
     */
    public function postAgentActionsByIdReject(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/agent-actions/{id}/reject'), $body, null, $idempotencyKey);
    }

    /**
     * Create an API key.
     *
     * POST /api-keys
     *
     * @return array<string,mixed>
     */
    public function postApiKeys(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/api-keys', $body, null, $idempotencyKey);
    }

    /**
     * Rotate an API key.
     *
     * POST /api-keys/{id}/rotate
     *
     * @return array<string,mixed>
     */
    public function postApiKeysByIdRotate(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/api-keys/{id}/rotate'), $body, null, $idempotencyKey);
    }

    /**
     * Alias of POST /contacts.
     *
     * POST /audiences/{audienceId}/contacts
     *
     * @return array<string,mixed>
     */
    public function postAudiencesByAudienceIdContacts(string $audienceId, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{audienceId}'], [$audienceId], '/audiences/{audienceId}/contacts'), $body, null, $idempotencyKey);
    }

    /**
     * Draft an automation: a trigger plus ordered steps (email, webhook, A/B split).
     *
     * POST /automations
     *
     * @return array<string,mixed>
     */
    public function postAutomations(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/automations', $body, null, $idempotencyKey);
    }

    /**
     * Stop firing.
     *
     * POST /automations/{id}/disable
     *
     * @return array<string,mixed>
     */
    public function postAutomationsByIdDisable(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/automations/{id}/disable'), null, null, $idempotencyKey);
    }

    /**
     * Snapshot trigger+steps as an immutable version and start firing.
     *
     * POST /automations/{id}/enable
     *
     * @return array<string,mixed>
     */
    public function postAutomationsByIdEnable(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/automations/{id}/enable'), null, null, $idempotencyKey);
    }

    /**
     * Console: start a Stripe Checkout for a tier, term and volume.
     *
     * POST /billing/checkout
     *
     * @return array<string,mixed>
     */
    public function postBillingCheckout(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/billing/checkout', $body, null, $idempotencyKey);
    }

    /**
     * Console: choose Free on the plan step.
     *
     * POST /billing/plan
     *
     * @return array<string,mixed>
     */
    public function postBillingPlan(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/billing/plan', $body, null, $idempotencyKey);
    }

    /**
     * Console: open the Stripe customer portal (plan changes, card, invoices).
     *
     * POST /billing/portal
     *
     * @return array<string,mixed>
     */
    public function postBillingPortal(?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/billing/portal', null, null, $idempotencyKey);
    }

    /**
     * Draft a broadcast to a segment.
     *
     * POST /broadcasts
     *
     * @return array<string,mixed>
     */
    public function postBroadcasts(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/broadcasts', $body, null, $idempotencyKey);
    }

    /**
     * Archive — reversible, any time, sent or draft.
     *
     * POST /broadcasts/{id}/archive
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdArchive(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/archive'), null, null, $idempotencyKey);
    }

    /**
     * Cancel a scheduled broadcast, or stop one that is still sending.
     *
     * POST /broadcasts/{id}/cancel
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdCancel(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/cancel'), null, null, $idempotencyKey);
    }

    /**
     * Send now, or schedule it with scheduled_at/scheduledAt — the same time grammar as PATCH /broadcasts/:id, refused if it is in the past.
     *
     * POST /broadcasts/{id}/send
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdSend(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/send'), $body, null, $idempotencyKey);
    }

    /**
     * Create or update a contact by email.
     *
     * POST /contacts
     *
     * @return array<string,mixed>
     */
    public function postContacts(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/contacts', $body, null, $idempotencyKey);
    }

    /**
     * Update up to 1,000 contacts that already exist.
     *
     * POST /contacts/batch
     *
     * @return array<string,mixed>
     */
    public function postContactsBatch(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/contacts/batch', $body, null, $idempotencyKey);
    }

    /**
     * Mark a contact active again after they opt back in.
     *
     * POST /contacts/{id}/resubscribe
     *
     * @return array<string,mixed>
     */
    public function postContactsByIdResubscribe(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/contacts/{id}/resubscribe'), $body, null, $idempotencyKey);
    }

    /**
     * Not available.
     *
     * POST /contacts/{id}/segments/{segmentId}
     *
     * @return array<string,mixed>
     */
    public function postContactsByIdSegmentsBySegmentId(string $id, string $segmentId, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}', '{segmentId}'], [$id, $segmentId], '/contacts/{id}/segments/{segmentId}'), null, null, $idempotencyKey);
    }

    /**
     * Update contacts from a CSV.
     *
     * POST /contacts/imports
     *
     * @return array<string,mixed>
     */
    public function postContactsImports(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/contacts/imports', $body, null, $idempotencyKey);
    }

    /**
     * Refused: a dedicated IP is assigned by us from the addresses we send from — ask support.
     *
     * POST /dedicated-ips
     *
     * @return array<string,mixed>
     */
    public function postDedicatedIps(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/dedicated-ips', $body, null, $idempotencyKey);
    }

    /**
     * Persist today rates as a snapshot row.
     *
     * POST /deliverability/domains/{id}/snapshot
     *
     * @return array<string,mixed>
     */
    public function postDeliverabilityDomainsByIdSnapshot(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/deliverability/domains/{id}/snapshot'), null, null, $idempotencyKey);
    }

    /**
     * Register a sending domain.
     *
     * POST /domains
     *
     * @return array<string,mixed>
     */
    public function postDomains(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/domains', $body, null, $idempotencyKey);
    }

    /**
     * Re-check every record and update the domain’s status.
     *
     * POST /domains/{id}/verify
     *
     * @return array<string,mixed>
     */
    public function postDomainsByIdVerify(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/domains/{id}/verify'), null, null, $idempotencyKey);
    }

    /**
     * Send an email.
     *
     * POST /emails
     *
     * @return array<string,mixed>
     */
    public function postEmails(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails', $body, null, $idempotencyKey);
    }

    /**
     * Send up to 500 emails.
     *
     * POST /emails/batch
     *
     * @return array<string,mixed>
     */
    public function postEmailsBatch(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/batch', $body, null, $idempotencyKey);
    }

    /**
     * Cancel many emails at once: by explicit ids or every scheduled/queued email before a time.
     *
     * POST /emails/bulk-cancel
     *
     * @return array<string,mixed>
     */
    public function postEmailsBulkCancel(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/bulk-cancel', $body, null, $idempotencyKey);
    }

    /**
     * Cancel a scheduled or queued email.
     *
     * POST /emails/{id}/cancel
     *
     * @return array<string,mixed>
     */
    public function postEmailsByIdCancel(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/{id}/cancel'), null, null, $idempotencyKey);
    }

    /**
     * Move an email to a new time.
     *
     * POST /emails/{id}/reschedule
     *
     * @return array<string,mixed>
     */
    public function postEmailsByIdReschedule(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/{id}/reschedule'), $body, null, $idempotencyKey);
    }

    /**
     * Deliverability lint without sending: a 0-100 placement score plus every finding with its fix.
     *
     * POST /emails/lint
     *
     * @return array<string,mixed>
     */
    public function postEmailsLint(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/lint', $body, null, $idempotencyKey);
    }

    /**
     * Run EVERY send gate without sending: domain, sandbox, trust, suppression, budget, loop, content, verifier.
     *
     * POST /emails/preflight
     *
     * @return array<string,mixed>
     */
    public function postEmailsPreflight(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/emails/preflight', $body, null, $idempotencyKey);
    }

    /**
     * Not open yet: reply to a received email.
     *
     * POST /emails/receiving/{id}/reply
     *
     * @return array<string,mixed>
     */
    public function postEmailsReceivingByIdReply(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/receiving/{id}/reply'), $body, null, $idempotencyKey);
    }

    /**
     * Ingest a custom event.
     *
     * POST /events
     *
     * @return array<string,mixed>
     */
    public function postEvents(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/events', $body, null, $idempotencyKey);
    }

    /**
     * Accept an invitation.
     *
     * POST /invite/{token}/accept
     *
     * @return array<string,mixed>
     */
    public function postInviteByTokenAccept(string $token, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{token}'], [$token], '/invite/{token}/accept'), null, null, $idempotencyKey);
    }

    /**
     * Stop this key from sending, immediately.
     *
     * POST /limits/keys/{id}/kill
     *
     * @return array<string,mixed>
     */
    public function postLimitsKeysByIdKill(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/limits/keys/{id}/kill'), $body, null, $idempotencyKey);
    }

    /**
     * Undo the kill switch.
     *
     * POST /limits/keys/{id}/resume
     *
     * @return array<string,mixed>
     */
    public function postLimitsKeysByIdResume(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/limits/keys/{id}/resume'), null, null, $idempotencyKey);
    }

    /**
     * Pause every key on this account at once.
     *
     * POST /limits/kill-all
     *
     * @return array<string,mixed>
     */
    public function postLimitsKillAll(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/limits/kill-all', $body, null, $idempotencyKey);
    }

    /**
     * Undo the global kill switch.
     *
     * POST /limits/resume-all
     *
     * @return array<string,mixed>
     */
    public function postLimitsResumeAll(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/limits/resume-all', $body, null, $idempotencyKey);
    }

    /**
     * Mark one notification read.
     *
     * POST /notifications/{id}/read
     *
     * @return array<string,mixed>
     */
    public function postNotificationsByIdRead(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/notifications/{id}/read'), null, null, $idempotencyKey);
    }

    /**
     * Mark notifications read.
     *
     * POST /notifications/read
     *
     * @return array<string,mixed>
     */
    public function postNotificationsRead(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/notifications/read', $body, null, $idempotencyKey);
    }

    /**
     * Create a dynamic segment.
     *
     * POST /segments
     *
     * @return array<string,mixed>
     */
    public function postSegments(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/segments', $body, null, $idempotencyKey);
    }

    /**
     * Open a support request.
     *
     * POST /support
     *
     * @return array<string,mixed>
     */
    public function postSupport(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/support', $body, null, $idempotencyKey);
    }

    /**
     * Rate a resolved support request, 1 to 5.
     *
     * POST /support/{ref}/csat
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefCsat(string $ref, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/csat'), $body, null, $idempotencyKey);
    }

    /**
     * Reply on a support request.
     *
     * POST /support/{ref}/messages
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefMessages(string $ref, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/messages'), $body, null, $idempotencyKey);
    }

    /**
     * Reopen a resolved request within 14 days.
     *
     * POST /support/{ref}/reopen
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefReopen(string $ref, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/reopen'), $body, null, $idempotencyKey);
    }

    /**
     * Mark a support request resolved.
     *
     * POST /support/{ref}/resolve
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefResolve(string $ref, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/resolve'), $body, null, $idempotencyKey);
    }

    /**
     * Stage files to attach to a support message.
     *
     * POST /support/uploads
     *
     * @return array<string,mixed>
     */
    public function postSupportUploads(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/support/uploads', $body, null, $idempotencyKey);
    }

    /**
     * Suppress an address or a whole domain (send "@example.com").
     *
     * POST /suppressions
     *
     * @return array<string,mixed>
     */
    public function postSuppressions(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/suppressions', $body, null, $idempotencyKey);
    }

    /**
     * Suppress up to 500 addresses in one call.
     *
     * POST /suppressions/batch/add
     *
     * @return array<string,mixed>
     */
    public function postSuppressionsBatchAdd(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/suppressions/batch/add', $body, null, $idempotencyKey);
    }

    /**
     * Lift up to 500 suppressions by address.
     *
     * POST /suppressions/batch/remove
     *
     * @return array<string,mixed>
     */
    public function postSuppressionsBatchRemove(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/suppressions/batch/remove', $body, null, $idempotencyKey);
    }

    /**
     * Invite an address at a role.
     *
     * POST /team/invites
     *
     * @return array<string,mixed>
     */
    public function postTeamInvites(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/team/invites', $body, null, $idempotencyKey);
    }

    /**
     * Add someone to this account.
     *
     * POST /team/members
     *
     * @return array<string,mixed>
     */
    public function postTeamMembers(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/team/members', $body, null, $idempotencyKey);
    }

    /**
     * Create a template draft.
     *
     * POST /templates
     *
     * @return array<string,mixed>
     */
    public function postTemplates(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/templates', $body, null, $idempotencyKey);
    }

    /**
     * Copy a template into a new editable draft.
     *
     * POST /templates/{id}/duplicate
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdDuplicate(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/duplicate'), $body, null, $idempotencyKey);
    }

    /**
     * Snapshot the draft as an immutable published version.
     *
     * POST /templates/{id}/publish
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdPublish(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/publish'), null, null, $idempotencyKey);
    }

    /**
     * Render a version with variables (defaults to the current published one; pass "draft" for the working copy).
     *
     * POST /templates/{id}/render
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdRender(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/render'), $body, null, $idempotencyKey);
    }

    /**
     * Start a new draft from an older version.
     *
     * POST /templates/{id}/rollback
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdRollback(string $id, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/rollback'), $body, null, $idempotencyKey);
    }

    /**
     * Create a subscription topic.
     *
     * POST /topics
     *
     * @return array<string,mixed>
     */
    public function postTopics(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/topics', $body, null, $idempotencyKey);
    }

    /**
     * File an appeal against the current standing.
     *
     * POST /trust/appeal
     *
     * @return array<string,mixed>
     */
    public function postTrustAppeal(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/trust/appeal', $body, null, $idempotencyKey);
    }

    /**
     * Tick or untick one remediation item.
     *
     * POST /trust/remediation
     *
     * @return array<string,mixed>
     */
    public function postTrustRemediation(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/trust/remediation', $body, null, $idempotencyKey);
    }

    /**
     * Register an endpoint.
     *
     * POST /webhooks
     *
     * @return array<string,mixed>
     */
    public function postWebhooks(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/webhooks', $body, null, $idempotencyKey);
    }

    /**
     * Re-send past events to this endpoint, by time range and/or a single event id.
     *
     * POST /webhooks/{id}/replay
     *
     * @return array<string,mixed>
     */
    public function postWebhooksByIdReplay(string $id, ?array $query = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/webhooks/{id}/replay'), null, $query, $idempotencyKey);
    }

    /**
     * Mint a new signing secret.
     *
     * POST /webhooks/{id}/rotate-secret
     *
     * @return array<string,mixed>
     */
    public function postWebhooksByIdRotateSecret(string $id, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/webhooks/{id}/rotate-secret'), null, null, $idempotencyKey);
    }

    /**
     * Stripe → AgentiSend: signature-verified, deduplicated by event id.
     *
     * POST /webhooks/stripe
     *
     * @return array<string,mixed>
     */
    public function postWebhooksStripe(?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/webhooks/stripe', null, null, $idempotencyKey);
    }

    /**
     * Console: turn opt-in overage on (with a ceiling of extra emails per period) or off.
     *
     * PUT /billing/overage
     *
     * @return array<string,mixed>
     */
    public function putBillingOverage(?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('PUT', '/billing/overage', $body, null, $idempotencyKey);
    }
}
