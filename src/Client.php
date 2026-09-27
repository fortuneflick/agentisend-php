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
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, ?array $body = null, ?array $query = null): array
    {
        $endpoint = $this->baseUrl . $path;
        if ($query !== null && $query !== []) {
            $endpoint .= '?' . http_build_query(array_filter($query, static fn ($v) => $v !== '' && $v !== null));
        }

        $headers = [
            'authorization: Bearer ' . $this->apiKey,
            'accept: application/json',
            'user-agent: agentisend-php',
        ];
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
     * Close this account: revoke every key, pause every budget, suspend sending. Data stays readable and exportable until the retention window ends.
     *
     * DELETE /account
     *
     * @return array<string,mixed>
     */
    public function deleteAccount(): array
    {
        return $this->request('DELETE', '/account', null, null);
    }

    /**
     * Revoke an API key immediately.
     *
     * DELETE /api-keys/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteApiKeysById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/api-keys/{id}'), null, null);
    }

    /**
     * Delete one contact and its properties.
     *
     * DELETE /contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteContactsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/contacts/{id}'), null, null);
    }

    /**
     * Release a dedicated IP. Traffic returns to shared automatically.
     *
     * DELETE /dedicated-ips/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteDedicatedIpsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/dedicated-ips/{id}'), null, null);
    }

    /**
     * Remove a domain and its records. Messages already sent keep their history.
     *
     * DELETE /domains/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteDomainsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/domains/{id}'), null, null);
    }

    /**
     * Forget an event definition. Ingest keeps accepting the event — it just stops being checked.
     *
     * DELETE /events/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteEventsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/events/{id}'), null, null);
    }

    /**
     * Revoke one assistant connection. Tokens and the backing key die immediately.
     *
     * DELETE /oauth/grants/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteOauthGrantsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/oauth/grants/{id}'), null, null);
    }

    /**
     * Delete a segment. Contacts are untouched.
     *
     * DELETE /segments/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteSegmentsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/segments/{id}'), null, null);
    }

    /**
     * Remove one suppression row. A hard bounce you have fixed can be cleared with an API key; an unsubscribe or a spam complaint is a person’s to lift, in the console.
     *
     * DELETE /suppressions/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteSuppressionsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/suppressions/{id}'), null, null);
    }

    /**
     * Cancel a pending invitation. The link in the email stops working immediately.
     *
     * DELETE /team/invites/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTeamInvitesById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/team/invites/{id}'), null, null);
    }

    /**
     * Remove a member. Their sends and keys stay; only their access ends. The last owner cannot be removed.
     *
     * DELETE /team/members/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTeamMembersById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/team/members/{id}'), null, null);
    }

    /**
     * Delete a topic and every recorded answer about it.
     *
     * DELETE /topics/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteTopicsById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/topics/{id}'), null, null);
    }

    /**
     * Delete an endpoint and its queued deliveries.
     *
     * DELETE /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteWebhooksById(string $id): array
    {
        return $this->request('DELETE', str_replace(['{id}'], [$id], '/webhooks/{id}'), null, null);
    }

    /**
     * This account: lifecycle status, the reason it is in that state, and when it was created. Sandboxed accounts may only send to their own verified domains.
     *
     * GET /account
     *
     * @return array<string,mixed>
     */
    public function getAccount(): array
    {
        return $this->request('GET', '/account', null, null);
    }

    /**
     * Everything this account owns, as a zip: emails.csv (inside your retention window), suppressions.csv and domains.json. No job, no wait.
     *
     * GET /account/export
     *
     * @return array<string,mixed>
     */
    public function getAccountExport(): array
    {
        return $this->request('GET', '/account/export', null, null);
    }

    /**
     * Every held agent action, newest first — nothing waits invisibly. Filter by state to read the inbox or the audit trail.
     *
     * GET /agent-actions
     *
     * @return array<string,mixed>
     */
    public function getAgentActions(?array $query = null): array
    {
        return $this->request('GET', '/agent-actions', null, $query);
    }

    /**
     * List API keys with 30-day request counts. Permission, domain scope and the key’s own budget ceiling are always returned (PRD F3); never the token.
     *
     * GET /api-keys
     *
     * @return array<string,mixed>
     */
    public function getApiKeys(?array $query = null): array
    {
        return $this->request('GET', '/api-keys', null, $query);
    }

    /**
     * Everything anyone changed on this account: keys, the kill switch, domains, approvals, standing, team and notification settings — with what each one looked like before. Newest first.
     *
     * GET /audit-log
     *
     * @return array<string,mixed>
     */
    public function getAuditLog(?array $query = null): array
    {
        return $this->request('GET', '/audit-log', null, $query);
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
        return $this->request('GET', '/automations', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}/runs'), null, $query);
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
        return $this->request('GET', str_replace(['{id}', '{run_id}'], [$id, $runid], '/automations/{id}/runs/{run_id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/automations/{id}/versions'), null, $query);
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
        return $this->request('GET', '/billing', null, null);
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
        return $this->request('GET', '/billing/plan', null, null);
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
        return $this->request('GET', '/billing/subscription', null, null);
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
        return $this->request('GET', '/broadcasts', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/broadcasts/{id}/messages'), null, $query);
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
        return $this->request('GET', '/contacts', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/contacts/{id}'), null, null);
    }

    /**
     * What this contact has said about every topic. A topic they never answered reports the topic default, and says so.
     *
     * GET /contacts/{id}/topics
     *
     * @return array<string,mixed>
     */
    public function getContactsByIdTopics(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/contacts/{id}/topics'), null, $query);
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
        return $this->request('GET', '/dedicated-ips', null, $query);
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
        return $this->request('GET', '/dedicated-ips/ramp', null, null);
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
        return $this->request('GET', str_replace(['{messageId}'], [$messageId], '/dedicated-ips/route-decision/{messageId}'), null, null);
    }

    /**
     * Aggregate authentication reports for your domains. Aligned and failing volume per day, and every address sending as you, flagged when it is not one of ours.
     *
     * GET /deliverability/dmarc
     *
     * @return array<string,mixed>
     */
    public function getDeliverabilityDmarc(?array $query = null): array
    {
        return $this->request('GET', '/deliverability/dmarc', null, $query);
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
        return $this->request('GET', '/deliverability/domains', null, $query);
    }

    /**
     * Per-domain reputation: live rates over the rolling window, daily snapshots, the thresholds those rates are judged against, the bounce breakdown by class with its remediation, and the receiving domains rejecting the most.
     *
     * GET /deliverability/domains/{id}
     *
     * @return array<string,mixed>
     */
    public function getDeliverabilityDomainsById(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/deliverability/domains/{id}'), null, $query);
    }

    /**
     * List domains. Items omit records; GET /domains/:id has the DNS sheet.
     *
     * GET /domains
     *
     * @return array<string,mixed>
     */
    public function getDomains(?array $query = null): array
    {
        return $this->request('GET', '/domains', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}'), null, null);
    }

    /**
     * Alias of GET /domains/:id/setup. Domain Connect: detect the DNS provider and hand back the exact records to add.
     *
     * GET /domains/{id}/connect
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdConnect(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/connect'), null, null);
    }

    /**
     * Newest-first timeline for one domain. Rows cover added, the first DNS check, each required record found or lost, verified, no longer verified, the 72-hour window closing, and deleted.
     *
     * GET /domains/{id}/events
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdEvents(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/events'), null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/setup'), null, null);
    }

    /**
     * Long-poll a domain until it is verified or failed, or until timeout (0–25 seconds). timeout=0 is a snapshot. Does not re-check DNS; the server checks on its own.
     *
     * GET /domains/{id}/wait
     *
     * @return array<string,mixed>
     */
    public function getDomainsByIdWait(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/domains/{id}/wait'), null, $query);
    }

    /**
     * List messages. Every console filter is a query param here (PRD F3).
     *
     * GET /emails
     *
     * @return array<string,mixed>
     */
    public function getEmails(?array $query = null): array
    {
        return $this->request('GET', '/emails', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}'), null, null);
    }

    /**
     * What this email carried. Ids are stable positions; bytes_available says whether the payload is still retrievable.
     *
     * GET /emails/{id}/attachments
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdAttachments(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/attachments'), null, $query);
    }

    /**
     * The bytes of one attachment, exactly as they were sent. Served as an inert download.
     *
     * GET /emails/{id}/attachments/{aid}
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdAttachmentsByAid(string $id, string $aid): array
    {
        return $this->request('GET', str_replace(['{id}', '{aid}'], [$id, $aid], '/emails/{id}/attachments/{aid}'), null, null);
    }

    /**
     * Download this email as .eml.
     *
     * GET /emails/{id}/eml
     *
     * @return array<string,mixed>
     */
    public function getEmailsByIdEml(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/eml'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/events'), null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/explain'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/{id}/mime'), null, null);
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
        return $this->request('GET', '/emails/export.csv', null, $query);
    }

    /**
     * Alias of GET /metrics. start_date and end_date are accepted as names for since and until.
     *
     * GET /emails/metrics
     *
     * @return array<string,mixed>
     */
    public function getEmailsMetrics(?array $query = null): array
    {
        return $this->request('GET', '/emails/metrics', null, $query);
    }

    /**
     * Mail received at this account’s domains, newest first. Filter by recipient, sender or date range.
     *
     * GET /emails/receiving
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceiving(?array $query = null): array
    {
        return $this->request('GET', '/emails/receiving', null, $query);
    }

    /**
     * One received email: headers, text and HTML bodies as data, and the attachments it carried.
     *
     * GET /emails/receiving/{id}
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}'), null, null);
    }

    /**
     * What this received email carried. Ids are stable positions in the message.
     *
     * GET /emails/receiving/{id}/attachments
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdAttachments(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}/attachments'), null, $query);
    }

    /**
     * The bytes of one received attachment, as an inert download — always octet-stream, never the sender’s declared type.
     *
     * GET /emails/receiving/{id}/attachments/{aid}
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdAttachmentsByAid(string $id, string $aid): array
    {
        return $this->request('GET', str_replace(['{id}', '{aid}'], [$id, $aid], '/emails/receiving/{id}/attachments/{aid}'), null, null);
    }

    /**
     * The stored RFC 5322 source, byte for byte. Served as an attachment with sniffing off — it is someone else’s content.
     *
     * GET /emails/receiving/{id}/raw
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingByIdRaw(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/emails/receiving/{id}/raw'), null, null);
    }

    /**
     * Received mail grouped into threads by Message-ID / In-Reply-To / References, newest thread first.
     *
     * GET /emails/receiving/threads
     *
     * @return array<string,mixed>
     */
    public function getEmailsReceivingThreads(?array $query = null): array
    {
        return $this->request('GET', '/emails/receiving/threads', null, $query);
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
        return $this->request('GET', '/events', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/events/{id}'), null, null);
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
        return $this->request('GET', '/health', null, null);
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
        return $this->request('GET', '/limits/keys', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/limits/keys/{id}'), null, null);
    }

    /**
     * Every API request this account made: method, route, status, duration and the refusal code. Filter by date range, status, status class, method, route or key.
     *
     * GET /logs
     *
     * @return array<string,mixed>
     */
    public function getLogs(?array $query = null): array
    {
        return $this->request('GET', '/logs', null, $query);
    }

    /**
     * One request, by log id. The x-request-id the caller saw is on the row.
     *
     * GET /logs/{id}
     *
     * @return array<string,mixed>
     */
    public function getLogsById(string $id): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/logs/{id}'), null, null);
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
        return $this->request('GET', '/metrics', null, $query);
    }

    /**
     * Conditions this account should know about. Open rows are live; resolved ones ended. One row per condition, counted, never one per re-fire.
     *
     * GET /notifications
     *
     * @return array<string,mixed>
     */
    public function getNotifications(?array $query = null): array
    {
        return $this->request('GET', '/notifications', null, $query);
    }

    /**
     * Which channels each notification type uses. A type with no stored row is on for both.
     *
     * GET /notifications/preferences
     *
     * @return array<string,mixed>
     */
    public function getNotificationsPreferences(): array
    {
        return $this->request('GET', '/notifications/preferences', null, null);
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
        return $this->request('GET', '/oauth/grants', null, $query);
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
        return $this->request('GET', '/openapi.json', null, null);
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
        return $this->request('GET', '/segments', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/segments/{id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/segments/{id}/members'), null, null);
    }

    /**
     * Public per-component health. Every component reports what it actually checked; one that cannot be checked says so instead of claiming green.
     *
     * GET /status
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        return $this->request('GET', '/status', null, null);
    }

    /**
     * Daily uptime per component over the last 90 days, aggregated from the scheduled probe's own samples. A day nobody measured reports no samples rather than 100%.
     *
     * GET /status/history
     *
     * @return array<string,mixed>
     */
    public function getStatusHistory(?array $query = null): array
    {
        return $this->request('GET', '/status/history', null, $query);
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
        return $this->request('GET', '/support', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/support/attachments/{id}/download'), null, null);
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
        return $this->request('GET', str_replace(['{ref}'], [$ref], '/support/{ref}'), null, null);
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
        return $this->request('GET', '/support/config', null, null);
    }

    /**
     * Every suppressed address or domain for this account (global platform rows included).
     *
     * GET /suppressions
     *
     * @return array<string,mixed>
     */
    public function getSuppressions(?array $query = null): array
    {
        return $this->request('GET', '/suppressions', null, $query);
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
        return $this->request('GET', '/team/invites', null, $query);
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
        return $this->request('GET', '/team/me', null, null);
    }

    /**
     * Everyone on this account and the role each one holds. Owner, admin, viewer — three roles, and seats are never billed per seat.
     *
     * GET /team/members
     *
     * @return array<string,mixed>
     */
    public function getTeamMembers(?array $query = null): array
    {
        return $this->request('GET', '/team/members', null, $query);
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
        return $this->request('GET', '/templates', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}/diff'), null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/templates/{id}/versions'), null, $query);
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
        return $this->request('GET', str_replace(['{id}', '{n}'], [$id, $n], '/templates/{id}/versions/{n}'), null, null);
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
        return $this->request('GET', '/topics', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/topics/{id}'), null, null);
    }

    /**
     * The remediation checklist ticks for this account. Per account, not per browser — the work one person does is done for everyone on it.
     *
     * GET /trust/remediation
     *
     * @return array<string,mixed>
     */
    public function getTrustRemediation(): array
    {
        return $this->request('GET', '/trust/remediation', null, null);
    }

    /**
     * This account’s standing, machine-readable. An agent can query it and back off before enforcement does it for them.
     *
     * GET /trust/standing
     *
     * @return array<string,mixed>
     */
    public function getTrustStanding(): array
    {
        return $this->request('GET', '/trust/standing', null, null);
    }

    /**
     * The published enforcement thresholds and the ladder they drive. Same constants the ladder acts on, so nothing that draws a line has to hard-code one.
     *
     * GET /trust/thresholds
     *
     * @return array<string,mixed>
     */
    public function getTrustThresholds(): array
    {
        return $this->request('GET', '/trust/thresholds', null, null);
    }

    /**
     * This period usage, per key: budget, consumed, remaining, rate window, last used. The bill, made legible.
     *
     * GET /usage
     *
     * @return array<string,mixed>
     */
    public function getUsage(): array
    {
        return $this->request('GET', '/usage', null, null);
    }

    /**
     * List endpoints. Secrets are never returned after creation.
     *
     * GET /webhooks
     *
     * @return array<string,mixed>
     */
    public function getWebhooks(?array $query = null): array
    {
        return $this->request('GET', '/webhooks', null, $query);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}'), null, null);
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
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}/dead-letters'), null, $query);
    }

    /**
     * Every delivery to this endpoint, with each attempt and what the receiver answered (A9).
     *
     * GET /webhooks/{id}/deliveries
     *
     * @return array<string,mixed>
     */
    public function getWebhooksByIdDeliveries(string $id, ?array $query = null): array
    {
        return $this->request('GET', str_replace(['{id}'], [$id], '/webhooks/{id}/deliveries'), null, $query);
    }

    /**
     * Console: the onboarding answers — your name, the company (which becomes the account name), the website, what you will send and roughly how much. Send completed: true to finish; only your name is required.
     *
     * PATCH /account/onboarding
     *
     * @return array<string,mixed>
     */
    public function patchAccountOnboarding(?array $body = null): array
    {
        return $this->request('PATCH', '/account/onboarding', $body, null);
    }

    /**
     * Rename a key or change its domain scope and scopes. The token is unchanged — use POST /api-keys/:id/rotate for that.
     *
     * PATCH /api-keys/{id}
     *
     * @return array<string,mixed>
     */
    public function patchApiKeysById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/api-keys/{id}'), $body, null);
    }

    /**
     * Edit a draft or disabled automation. Enabling snapshots a version — a running automation never executes a half-edited definition.
     *
     * PATCH /automations/{id}
     *
     * @return array<string,mixed>
     */
    public function patchAutomationsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/automations/{id}'), $body, null);
    }

    /**
     * Edit a DRAFT — or rename ANY broadcast. Sent content is immutable.
     *
     * PATCH /broadcasts/{id}
     *
     * @return array<string,mixed>
     */
    public function patchBroadcastsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/broadcasts/{id}'), $body, null);
    }

    /**
     * Edit one contact by id: address, status, properties. Properties merge; an explicit null removes one.
     *
     * PATCH /contacts/{id}
     *
     * @return array<string,mixed>
     */
    public function patchContactsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/contacts/{id}'), $body, null);
    }

    /**
     * Record this contact’s answer for one or more topics. Answers are absolute — nothing is inferred from what is left out.
     *
     * PATCH /contacts/{id}/topics
     *
     * @return array<string,mixed>
     */
    public function patchContactsByIdTopics(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/contacts/{id}/topics'), $body, null);
    }

    /**
     * Turn click or open tracking on, or change the tracking subdomain. Name, region and return-path cannot change.
     *
     * PATCH /domains/{id}
     *
     * @return array<string,mixed>
     */
    public function patchDomainsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/domains/{id}'), $body, null);
    }

    /**
     * Move a scheduled email to a new time — the same act as POST /emails/:id/reschedule, under the verb a Resend integration already uses.
     *
     * PATCH /emails/{id}
     *
     * @return array<string,mixed>
     */
    public function patchEmailsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/emails/{id}'), $body, null);
    }

    /**
     * Declare or re-declare an event’s fields. Adding a field to a strict event starts refusing payloads that omit it — that is the point.
     *
     * PATCH /events/{id}
     *
     * @return array<string,mixed>
     */
    public function patchEventsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/events/{id}'), $body, null);
    }

    /**
     * Set or clear this key’s period budget and per-minute ceiling. An API key may lower its own; raising one is a person’s decision, made in the console.
     *
     * PATCH /limits/keys/{id}
     *
     * @return array<string,mixed>
     */
    public function patchLimitsKeysById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/limits/keys/{id}'), $body, null);
    }

    /**
     * Turn a notification type on or off per channel. The gate is the type — there is no severity to mute instead.
     *
     * PATCH /notifications/preferences
     *
     * @return array<string,mixed>
     */
    public function patchNotificationsPreferences(?array $body = null): array
    {
        return $this->request('PATCH', '/notifications/preferences', $body, null);
    }

    /**
     * Rename or change the rules. Membership re-evaluates immediately.
     *
     * PATCH /segments/{id}
     *
     * @return array<string,mixed>
     */
    public function patchSegmentsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/segments/{id}'), $body, null);
    }

    /**
     * Change a member’s role. The last owner cannot be demoted.
     *
     * PATCH /team/members/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTeamMembersById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/team/members/{id}'), $body, null);
    }

    /**
     * Edit the draft. Published templates are immutable.
     *
     * PATCH /templates/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTemplatesById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/templates/{id}'), $body, null);
    }

    /**
     * Rename a topic or change its default. Changing the default never rewrites an answer a contact already gave.
     *
     * PATCH /topics/{id}
     *
     * @return array<string,mixed>
     */
    public function patchTopicsById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/topics/{id}'), $body, null);
    }

    /**
     * Update url, subscribed events, or the disabled flag.
     *
     * PATCH /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function patchWebhooksById(string $id, ?array $body = null): array
    {
        return $this->request('PATCH', str_replace(['{id}'], [$id], '/webhooks/{id}'), $body, null);
    }

    /**
     * Execute the held send through the normal accept path. A person signed in to the console decides; the action row records who and when.
     *
     * POST /agent-actions/{id}/approve
     *
     * @return array<string,mixed>
     */
    public function postAgentActionsByIdApprove(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/agent-actions/{id}/approve'), null, null);
    }

    /**
     * Refuse the held send. A person signed in to the console decides; the reason is preserved with the row.
     *
     * POST /agent-actions/{id}/reject
     *
     * @return array<string,mixed>
     */
    public function postAgentActionsByIdReject(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/agent-actions/{id}/reject'), $body, null);
    }

    /**
     * Create an API key. The token is shown exactly once.
     *
     * POST /api-keys
     *
     * @return array<string,mixed>
     */
    public function postApiKeys(?array $body = null): array
    {
        return $this->request('POST', '/api-keys', $body, null);
    }

    /**
     * Rotate an API key. The new token is returned once; the token it replaces keeps working for grace_hours (0, 1 or 24).
     *
     * POST /api-keys/{id}/rotate
     *
     * @return array<string,mixed>
     */
    public function postApiKeysByIdRotate(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/api-keys/{id}/rotate'), $body, null);
    }

    /**
     * Draft an automation: a trigger plus ordered steps (email, webhook, A/B split).
     *
     * POST /automations
     *
     * @return array<string,mixed>
     */
    public function postAutomations(?array $body = null): array
    {
        return $this->request('POST', '/automations', $body, null);
    }

    /**
     * Stop firing. Versions stay for audit.
     *
     * POST /automations/{id}/disable
     *
     * @return array<string,mixed>
     */
    public function postAutomationsByIdDisable(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/automations/{id}/disable'), null, null);
    }

    /**
     * Snapshot trigger+steps as an immutable version and start firing.
     *
     * POST /automations/{id}/enable
     *
     * @return array<string,mixed>
     */
    public function postAutomationsByIdEnable(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/automations/{id}/enable'), null, null);
    }

    /**
     * Console: start a Stripe Checkout for a tier, term and volume.
     *
     * POST /billing/checkout
     *
     * @return array<string,mixed>
     */
    public function postBillingCheckout(?array $body = null): array
    {
        return $this->request('POST', '/billing/checkout', $body, null);
    }

    /**
     * Console: choose Free on the plan step. Paid plans go through POST /billing/checkout. Idempotent; owner only.
     *
     * POST /billing/plan
     *
     * @return array<string,mixed>
     */
    public function postBillingPlan(?array $body = null): array
    {
        return $this->request('POST', '/billing/plan', $body, null);
    }

    /**
     * Console: open the Stripe customer portal (plan changes, card, invoices).
     *
     * POST /billing/portal
     *
     * @return array<string,mixed>
     */
    public function postBillingPortal(): array
    {
        return $this->request('POST', '/billing/portal', null, null);
    }

    /**
     * Draft a broadcast to a segment. Content comes from a template version or an inline body.
     *
     * POST /broadcasts
     *
     * @return array<string,mixed>
     */
    public function postBroadcasts(?array $body = null): array
    {
        return $this->request('POST', '/broadcasts', $body, null);
    }

    /**
     * Archive — reversible, any time, sent or draft.
     *
     * POST /broadcasts/{id}/archive
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdArchive(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/archive'), null, null);
    }

    /**
     * Cancel a scheduled broadcast before it sends. It returns to draft, editable and re-schedulable.
     *
     * POST /broadcasts/{id}/cancel
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdCancel(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/cancel'), null, null);
    }

    /**
     * Send now. Content is SNAPSHOTTED: the segment is evaluated and every member rendered with their properties; the result is immutable.
     *
     * POST /broadcasts/{id}/send
     *
     * @return array<string,mixed>
     */
    public function postBroadcastsByIdSend(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/broadcasts/{id}/send'), null, null);
    }

    /**
     * Create or update a contact by email. Properties are typed from their value and auto-created — nothing needs pre-declaring.
     *
     * POST /contacts
     *
     * @return array<string,mixed>
     */
    public function postContacts(?array $body = null): array
    {
        return $this->request('POST', '/contacts', $body, null);
    }

    /**
     * Provision a dedicated IP for this account. It starts warming on the published curve.
     *
     * POST /dedicated-ips
     *
     * @return array<string,mixed>
     */
    public function postDedicatedIps(?array $body = null): array
    {
        return $this->request('POST', '/dedicated-ips', $body, null);
    }

    /**
     * Persist today rates as a snapshot row. Idempotent per domain per day.
     *
     * POST /deliverability/domains/{id}/snapshot
     *
     * @return array<string,mixed>
     */
    public function postDeliverabilityDomainsByIdSnapshot(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/deliverability/domains/{id}/snapshot'), null, null);
    }

    /**
     * Register a sending domain. Region is optional and defaults to us (Oregon); the stored value is returned.
     *
     * POST /domains
     *
     * @return array<string,mixed>
     */
    public function postDomains(?array $body = null): array
    {
        return $this->request('POST', '/domains', $body, null);
    }

    /**
     * Re-check every record and update the domain’s status.
     *
     * POST /domains/{id}/verify
     *
     * @return array<string,mixed>
     */
    public function postDomainsByIdVerify(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/domains/{id}/verify'), null, null);
    }

    /**
     * Send an email. Returns the message id; delivery happens on the queue. Before a domain is verified, from onboarding@agentisend.com delivers only to this account's member sign-in addresses (20 per UTC day, no cc/bcc/attachments/tracking/extra headers). Addresses ending in @simulator.agentisend.com cost nothing and reach nobody.
     *
     * POST /emails
     *
     * @return array<string,mixed>
     */
    public function postEmails(?array $body = null): array
    {
        return $this->request('POST', '/emails', $body, null);
    }

    /**
     * Send up to 500 emails. Each item succeeds or fails on its own — read data[i].status.
     *
     * POST /emails/batch
     *
     * @return array<string,mixed>
     */
    public function postEmailsBatch(?array $body = null): array
    {
        return $this->request('POST', '/emails/batch', $body, null);
    }

    /**
     * Cancel many emails at once: by explicit ids or every scheduled/queued email before a time.
     *
     * POST /emails/bulk-cancel
     *
     * @return array<string,mixed>
     */
    public function postEmailsBulkCancel(?array $body = null): array
    {
        return $this->request('POST', '/emails/bulk-cancel', $body, null);
    }

    /**
     * Cancel a scheduled or queued email. Already-sent mail is history, not cancellable.
     *
     * POST /emails/{id}/cancel
     *
     * @return array<string,mixed>
     */
    public function postEmailsByIdCancel(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/{id}/cancel'), null, null);
    }

    /**
     * Move an email to a new time. Works from scheduled AND canceled — a cancel is never terminal.
     *
     * POST /emails/{id}/reschedule
     *
     * @return array<string,mixed>
     */
    public function postEmailsByIdReschedule(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/{id}/reschedule'), $body, null);
    }

    /**
     * Deliverability lint without sending: a 0-100 placement score plus every finding with its fix.
     *
     * POST /emails/lint
     *
     * @return array<string,mixed>
     */
    public function postEmailsLint(?array $body = null): array
    {
        return $this->request('POST', '/emails/lint', $body, null);
    }

    /**
     * Run EVERY send gate without sending: domain, sandbox, trust, suppression, budget, loop, content, verifier. The report matches what POST /emails would do, byte for byte — one code path.
     *
     * POST /emails/preflight
     *
     * @return array<string,mixed>
     */
    public function postEmailsPreflight(?array $body = null): array
    {
        return $this->request('POST', '/emails/preflight', $body, null);
    }

    /**
     * Reply to a received email. Threads on In-Reply-To and References, and runs every send check.
     *
     * POST /emails/receiving/{id}/reply
     *
     * @return array<string,mixed>
     */
    public function postEmailsReceivingByIdReply(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/emails/receiving/{id}/reply'), $body, null);
    }

    /**
     * Ingest a custom event. Matching enabled automations fire synchronously (202 once enqueued).
     *
     * POST /events
     *
     * @return array<string,mixed>
     */
    public function postEvents(?array $body = null): array
    {
        return $this->request('POST', '/events', $body, null);
    }

    /**
     * Accept an invitation. Requires a session signed in as the invited address; a person who has never signed in before is provisioned into the inviting account, not a new one.
     *
     * POST /invite/{token}/accept
     *
     * @return array<string,mixed>
     */
    public function postInviteByTokenAccept(string $token): array
    {
        return $this->request('POST', str_replace(['{token}'], [$token], '/invite/{token}/accept'), null, null);
    }

    /**
     * Stop this key from sending, immediately. Takes effect on the next request.
     *
     * POST /limits/keys/{id}/kill
     *
     * @return array<string,mixed>
     */
    public function postLimitsKeysByIdKill(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/limits/keys/{id}/kill'), $body, null);
    }

    /**
     * Undo the kill switch. A person signed in to the console does this; budgets and ceilings are unchanged.
     *
     * POST /limits/keys/{id}/resume
     *
     * @return array<string,mixed>
     */
    public function postLimitsKeysByIdResume(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/limits/keys/{id}/resume'), null, null);
    }

    /**
     * Pause every key on this account at once. Takes effect on the next request.
     *
     * POST /limits/kill-all
     *
     * @return array<string,mixed>
     */
    public function postLimitsKillAll(?array $body = null): array
    {
        return $this->request('POST', '/limits/kill-all', $body, null);
    }

    /**
     * Undo the global kill switch. A person signed in to the console does this; budgets and ceilings are unchanged.
     *
     * POST /limits/resume-all
     *
     * @return array<string,mixed>
     */
    public function postLimitsResumeAll(?array $body = null): array
    {
        return $this->request('POST', '/limits/resume-all', $body, null);
    }

    /**
     * Mark one notification read. Reading it never resolves it — the condition ends the row, not the reader.
     *
     * POST /notifications/{id}/read
     *
     * @return array<string,mixed>
     */
    public function postNotificationsByIdRead(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/notifications/{id}/read'), null, null);
    }

    /**
     * Mark notifications read. With no ids, every open row on the account is marked — the bell’s "Mark all read".
     *
     * POST /notifications/read
     *
     * @return array<string,mixed>
     */
    public function postNotificationsRead(?array $body = null): array
    {
        return $this->request('POST', '/notifications/read', $body, null);
    }

    /**
     * Create a dynamic segment. Rules AND together over properties and status.
     *
     * POST /segments
     *
     * @return array<string,mixed>
     */
    public function postSegments(?array $body = null): array
    {
        return $this->request('POST', '/segments', $body, null);
    }

    /**
     * Open a support request.
     *
     * POST /support
     *
     * @return array<string,mixed>
     */
    public function postSupport(?array $body = null): array
    {
        return $this->request('POST', '/support', $body, null);
    }

    /**
     * Rate a resolved support request, 1 to 5.
     *
     * POST /support/{ref}/csat
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefCsat(string $ref, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/csat'), $body, null);
    }

    /**
     * Reply on a support request.
     *
     * POST /support/{ref}/messages
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefMessages(string $ref, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/messages'), $body, null);
    }

    /**
     * Reopen a resolved request within 14 days.
     *
     * POST /support/{ref}/reopen
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefReopen(string $ref, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/reopen'), $body, null);
    }

    /**
     * Mark a support request resolved.
     *
     * POST /support/{ref}/resolve
     *
     * @return array<string,mixed>
     */
    public function postSupportByRefResolve(string $ref, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{ref}'], [$ref], '/support/{ref}/resolve'), $body, null);
    }

    /**
     * Stage files to attach to a support message. JSON base64; 5 files, 10 MB each, 25 MB total.
     *
     * POST /support/uploads
     *
     * @return array<string,mixed>
     */
    public function postSupportUploads(?array $body = null): array
    {
        return $this->request('POST', '/support/uploads', $body, null);
    }

    /**
     * Suppress an address or a whole domain (send "@example.com"). Idempotent — an existing row is returned.
     *
     * POST /suppressions
     *
     * @return array<string,mixed>
     */
    public function postSuppressions(?array $body = null): array
    {
        return $this->request('POST', '/suppressions', $body, null);
    }

    /**
     * Suppress up to 500 addresses in one call. Each item succeeds or fails on its own — read data[i].status.
     *
     * POST /suppressions/batch/add
     *
     * @return array<string,mixed>
     */
    public function postSuppressionsBatchAdd(?array $body = null): array
    {
        return $this->request('POST', '/suppressions/batch/add', $body, null);
    }

    /**
     * Lift up to 500 suppressions by address. An address that was not suppressed reads not_found, not an error.
     *
     * POST /suppressions/batch/remove
     *
     * @return array<string,mixed>
     */
    public function postSuppressionsBatchRemove(?array $body = null): array
    {
        return $this->request('POST', '/suppressions/batch/remove', $body, null);
    }

    /**
     * Invite an address at a role. The mail carries a link that only works while signed in as that address.
     *
     * POST /team/invites
     *
     * @return array<string,mixed>
     */
    public function postTeamInvites(?array $body = null): array
    {
        return $this->request('POST', '/team/invites', $body, null);
    }

    /**
     * Add someone to this account. Membership begins when they accept, so this returns the pending invitation — the same call as POST /team/invites.
     *
     * POST /team/members
     *
     * @return array<string,mixed>
     */
    public function postTeamMembers(?array $body = null): array
    {
        return $this->request('POST', '/team/members', $body, null);
    }

    /**
     * Create a template draft.
     *
     * POST /templates
     *
     * @return array<string,mixed>
     */
    public function postTemplates(?array $body = null): array
    {
        return $this->request('POST', '/templates', $body, null);
    }

    /**
     * Copy a template into a new editable draft. The copy carries no version history.
     *
     * POST /templates/{id}/duplicate
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdDuplicate(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/duplicate'), $body, null);
    }

    /**
     * Snapshot the draft as an immutable published version.
     *
     * POST /templates/{id}/publish
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdPublish(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/publish'), null, null);
    }

    /**
     * Render a version with variables (defaults to the current published one; pass "draft" for the working copy). Unknown variables fail with the names listed.
     *
     * POST /templates/{id}/render
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdRender(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/render'), $body, null);
    }

    /**
     * Rollback creates a NEW draft from an older version and puts the template back into draft - history is never rewritten, and what is live does not move until that draft is published.
     *
     * POST /templates/{id}/rollback
     *
     * @return array<string,mixed>
     */
    public function postTemplatesByIdRollback(string $id, ?array $body = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/templates/{id}/rollback'), $body, null);
    }

    /**
     * Create a subscription topic. default_subscribed=false makes it opt-in: silence means no.
     *
     * POST /topics
     *
     * @return array<string,mixed>
     */
    public function postTopics(?array $body = null): array
    {
        return $this->request('POST', '/topics', $body, null);
    }

    /**
     * File an appeal against the current standing. The reason is recorded verbatim; a human answers by sla_deadline_at.
     *
     * POST /trust/appeal
     *
     * @return array<string,mixed>
     */
    public function postTrustAppeal(?array $body = null): array
    {
        return $this->request('POST', '/trust/appeal', $body, null);
    }

    /**
     * Tick or untick one remediation item. Returns the whole checklist.
     *
     * POST /trust/remediation
     *
     * @return array<string,mixed>
     */
    public function postTrustRemediation(?array $body = null): array
    {
        return $this->request('POST', '/trust/remediation', $body, null);
    }

    /**
     * Register an endpoint. The signing secret is returned exactly once.
     *
     * POST /webhooks
     *
     * @return array<string,mixed>
     */
    public function postWebhooks(?array $body = null): array
    {
        return $this->request('POST', '/webhooks', $body, null);
    }

    /**
     * Re-send past events to this endpoint, by time range and/or a single event id (PRD B2).
     *
     * POST /webhooks/{id}/replay
     *
     * @return array<string,mixed>
     */
    public function postWebhooksByIdReplay(string $id, ?array $query = null): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/webhooks/{id}/replay'), null, $query);
    }

    /**
     * Mint a new signing secret. Returned once. The previous secret keeps verifying for 24 hours, and deliveries in that window are signed with both.
     *
     * POST /webhooks/{id}/rotate-secret
     *
     * @return array<string,mixed>
     */
    public function postWebhooksByIdRotateSecret(string $id): array
    {
        return $this->request('POST', str_replace(['{id}'], [$id], '/webhooks/{id}/rotate-secret'), null, null);
    }

    /**
     * Stripe → AgentiSend: signature-verified, deduplicated by event id.
     *
     * POST /webhooks/stripe
     *
     * @return array<string,mixed>
     */
    public function postWebhooksStripe(): array
    {
        return $this->request('POST', '/webhooks/stripe', null, null);
    }

    /**
     * Console: turn opt-in overage on (with a ceiling of extra emails per period) or off. Owner only; paid plans only.
     *
     * PUT /billing/overage
     *
     * @return array<string,mixed>
     */
    public function putBillingOverage(?array $body = null): array
    {
        return $this->request('PUT', '/billing/overage', $body, null);
    }
}
