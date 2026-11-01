<?php

namespace App\Services\Webhooks;

use App\Data\Webhooks\WebhookResult;
use App\Enums\WebhookFailure;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;

/**
 * One attempt to tell an address something, and nothing else: no state is kept here.
 *
 * What a name points at can change between saving and sending, and between two lookups, so each attempt resolves it,
 * refuses if any answer leads inside, and connects to exactly those addresses: the connection does not look the name
 * up again (DNS rebinding). A move elsewhere is not followed, and no proxy is used, for the same reason.
 *
 * The receiver checks `X-Webhook-Signature`: "sha256=" and the HMAC-SHA256, keyed by the webhook's secret, of
 * "<X-Webhook-Timestamp>.<raw body>". Having the time inside lets it turn away an old request played again.
 */
class WebhookSender
{
    private const USER_AGENT = 'IDCardPro-Webhooks/1';

    public function __construct(private WebhookHostResolver $resolver) {}

    public function send(Webhook $webhook, WebhookDelivery $delivery): WebhookResult
    {
        // Again here: the rules may be tighter now than when the address was saved
        if (WebhookAddress::problem($webhook->url) !== null) {
            return new WebhookResult(WebhookFailure::Refused);
        }

        $pin = $this->pin($webhook->url);
        if ($pin instanceof WebhookFailure) {
            return new WebhookResult($pin);
        }

        $body = $delivery->payload;
        $timestamp = (string) now()->getTimestamp();

        try {
            $response = Http::connectTimeout((int) config('webhooks.connect_timeout'))
                ->timeout((int) config('webhooks.timeout'))
                ->withoutRedirecting()
                ->withUserAgent(self::USER_AGENT)
                ->withOptions(['proxy' => '', 'curl' => $pin === [] ? [] : [CURLOPT_RESOLVE => $pin]])
                ->sink('/dev/null')
                ->withHeaders([
                    'X-Webhook-Id' => $delivery->receiverId(),
                    'X-Webhook-Event' => $delivery->event,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $webhook->secret),
                ])
                ->withBody($body)
                ->post($webhook->url);
        } catch (HttpClientException $exception) {
            return new WebhookResult($this->failureOf($exception));
        }

        return match (true) {
            $response->successful() => new WebhookResult(code: $response->status()),
            $response->redirect() => new WebhookResult(WebhookFailure::Redirected, $response->status()),
            default => new WebhookResult(WebhookFailure::Rejected, $response->status()),
        };
    }

    /**
     * Where to connect, as curl takes it ("host:port:ip,ip"); nothing to pin when the address is itself an IP.
     *
     * @return array<int, string>|WebhookFailure
     */
    private function pin(string $url): array|WebhookFailure
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            return WebhookFailure::Unresolved;
        }
        if (! config('webhooks.allow_private') && array_filter($addresses, fn (string $ip) => ! WebhookAddress::isPublic($ip)) !== []) {
            return WebhookFailure::Refused;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $list = array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $addresses);

        return ["{$host}:{$port}:".implode(',', $list)];
    }

    private function failureOf(HttpClientException $exception): WebhookFailure
    {
        return match (preg_match('/cURL error (\d+)/', $exception->getMessage(), $error) === 1 ? (int) $error[1] : 0) {
            CURLE_OPERATION_TIMEDOUT => WebhookFailure::Timeout,
            CURLE_SSL_CACERT, CURLE_SSL_CERTPROBLEM => WebhookFailure::Certificate,
            default => WebhookFailure::Unreachable,
        };
    }
}
