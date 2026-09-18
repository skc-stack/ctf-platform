<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Support\Config;
use CTF\Server\Support\Logger;

/**
 * Nylas API v3 client for sending email.
 *
 * Endpoint: POST {NYLAS_API_URI}/v3/grants/{NYLAS_GRANT_ID}/messages/send
 * Ref: https://developer.nylas.com/
 *
 * Uses PHP streams (file_get_contents + stream_context_create) rather than
 * the cURL extension so it works on any PHP install with allow_url_fopen,
 * including Windows dev environments where curl isn't loadable in mod_php.
 *
 * Required config (.env):
 *   NYLAS_API_URI     e.g. https://api.us.nylas.com
 *   NYLAS_API_KEY     Bearer token
 *   NYLAS_GRANT_ID    UUID of the granted account
 *   NYLAS_FROM_NAME   Display name (optional)
 *   NYLAS_FROM_EMAIL  Sender email (optional)
 */
final class NylasClient
{
    private string $apiUri;
    private string $apiKey;
    private string $grantId;
    private string $fromName;
    private string $fromEmail;

    public function __construct()
    {
        $this->apiUri    = rtrim((string)Config::get('NYLAS_API_URI', ''), '/');
        $this->apiKey    = (string)Config::get('NYLAS_API_KEY', '');
        $this->grantId   = (string)Config::get('NYLAS_GRANT_ID', '');
        $this->fromName  = (string)Config::get('NYLAS_FROM_NAME', 'CTF LAB');
        $this->fromEmail = (string)Config::get('NYLAS_FROM_EMAIL', '');
    }

    /**
     * Send an email via Nylas HTTP API.
     *
     * @return array Nylas response payload (decoded JSON)
     * @throws \RuntimeException on transport or API error
     */
    public function send(string $toEmail, string $subject, string $bodyHtml, ?string $toName = null): array
    {
        if ($this->apiUri === '' || $this->apiKey === '' || $this->grantId === '') {
            throw new \RuntimeException('Nylas config missing (NYLAS_API_URI / NYLAS_API_KEY / NYLAS_GRANT_ID)');
        }
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid recipient email: $toEmail");
        }

        $url = sprintf('%s/v3/grants/%s/messages/send', $this->apiUri, $this->grantId);

        $payload = [
            'subject'   => $subject,
            'body'      => trim(strip_tags($bodyHtml)),
            'body_html' => $bodyHtml,
            'to'        => [array_filter([
                'name'  => $toName,
                'email' => $toEmail,
            ], static fn($v) => $v !== null && $v !== '')],
        ];
        if ($this->fromEmail !== '') {
            $payload['from'] = [array_filter([
                'name'  => $this->fromName,
                'email' => $this->fromEmail,
            ], static fn($v) => $v !== null && $v !== '')];
        }

        // SSL verification: strict in production, lenient in local dev where
        // CA bundles are hard to set up (Windows). Configurable via .env
        // (NYLAS_VERIFY_SSL = "1" forces strict; "0" forces off; default =
        // strict in production, off in local).
        $isProduction = Config::get('APP_ENV', 'local') === 'production';
        $verifyOverride = Config::get('NYLAS_VERIFY_SSL', null);
        if ($verifyOverride !== null) {
            $verifyPeer = $verifyOverride === '1' || $verifyOverride === 'true';
        } else {
            $verifyPeer = $isProduction;
        }
        if (!$verifyPeer && !$isProduction) {
            Logger::get()->warning('nylas.ssl_verify_off', [
                'reason' => 'APP_ENV != production and no NYLAS_VERIFY_SSL override',
            ]);
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: CTF-LAB/0.4 (PHP)',
                ],
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout'       => 30,
                'ignore_errors' => true,   // capture body even on 4xx/5xx
                'protocol_version' => '1.1',
            ],
            'ssl' => [
                'verify_peer'      => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $err = \error_get_last()['message'] ?? 'unknown';
            throw new \RuntimeException('Nylas stream error: ' . $err);
        }

        // Parse status code from $http_response_header (auto-populated by PHP)
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        $decoded = json_decode((string)$resp, true);
        if ($status >= 400) {
            $msg = is_array($decoded)
                ? ($decoded['error']['message'] ?? $decoded['message'] ?? 'unknown error')
                : 'non-JSON response';
            throw new \RuntimeException(sprintf('Nylas API error %d: %s', $status, $msg));
        }

        Logger::get()->info('nylas.send', [
            'to'         => $toEmail,
            'subject'    => $subject,
            'status'     => $status,
            'message_id' => is_array($decoded) ? ($decoded['data']['id'] ?? null) : null,
        ]);

        return is_array($decoded) ? $decoded : [];
    }

    public function isConfigured(): bool
    {
        return $this->apiUri !== '' && $this->apiKey !== '' && $this->grantId !== '';
    }
}
