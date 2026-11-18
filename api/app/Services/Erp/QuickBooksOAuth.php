<?php

namespace App\Services\Erp;

use App\Models\ErpConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * QuickBooks Online OAuth 2.0 (authorization code). The app's client id/secret live in .env;
 * the tokens Intuit issues are stored encrypted on the company's erp_connections row and
 * refreshed automatically (access tokens last an hour, refresh tokens ~100 days).
 */
class QuickBooksOAuth
{
    public const SCOPE = 'com.intuit.quickbooks.accounting';

    private function cfg(string $key): mixed
    {
        return config("extractiq.erp.drivers.quickbooks.$key");
    }

    public function configured(): bool
    {
        return filled($this->cfg('client_id')) && filled($this->cfg('client_secret'));
    }

    /** The Intuit consent URL; `state` ties the callback back to this company and user. */
    public function authorizeUrl(int $companyId, int $userId): string
    {
        if (! $this->configured()) {
            throw new ErpException('QBO_CLIENT_ID / QBO_CLIENT_SECRET are not set.', retryable: false);
        }
        $state = Str::random(40);
        Cache::put("qbo-oauth:$state", ['company_id' => $companyId, 'user_id' => $userId], now()->addMinutes(10));

        return $this->cfg('authorize_url').'?'.http_build_query([
            'client_id' => $this->cfg('client_id'), 'response_type' => 'code', 'scope' => self::SCOPE,
            'redirect_uri' => $this->cfg('redirect_uri'), 'state' => $state,
        ]);
    }

    /** Exchange the callback's code for tokens and store them. Returns the connection. */
    public function complete(string $state, string $code, string $realmId): ErpConnection
    {
        $ctx = Cache::pull("qbo-oauth:$state");
        if (! $ctx) {
            throw new ErpException('The QuickBooks sign-in expired or was already used. Start again.', retryable: false);
        }
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->cfg('redirect_uri')]);
        $connection = ErpConnection::for($ctx['company_id'], 'quickbooks');
        $connection->update(['credentials' => $this->credentials($tokens, $realmId), 'status' => 'connected', 'status_message' => 'Connected to QuickBooks (realm '.$realmId.').', 'tested_at' => now()]);

        return $connection;
    }

    /** A valid access token, refreshing (and saving) it when it's about to expire. */
    public function accessToken(ErpConnection $connection): array
    {
        $c = $connection->credentials ?? [];
        if (empty($c['access_token']) || empty($c['realm_id'])) {
            throw new ErpException('Not connected to QuickBooks. Use "Connect to QuickBooks" on the ERP Sync page.', retryable: false);
        }
        if (Carbon::parse($c['expires_at'] ?? 0)->subSeconds(60)->isPast()) {
            $c = $this->refresh($connection);
        }

        return $c;
    }

    public function refresh(ErpConnection $connection): array
    {
        $c = $connection->credentials ?? [];
        if (empty($c['refresh_token'])) {
            throw new ErpException('QuickBooks refresh token missing; connect again.', retryable: false);
        }
        $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token']]);
        $creds = $this->credentials($tokens, $c['realm_id']);
        $connection->update(['credentials' => $creds]);

        return $creds;
    }

    public function disconnect(ErpConnection $connection): void
    {
        $token = $connection->credentials['refresh_token'] ?? null;
        if ($token && $this->configured()) {
            Http::withBasicAuth($this->cfg('client_id'), $this->cfg('client_secret'))->acceptJson()->asJson()
                ->post($this->cfg('revoke_url'), ['token' => $token]); // best effort
        }
        $connection->update(['credentials' => null, 'status' => 'not_configured', 'status_message' => 'Disconnected from QuickBooks.', 'is_active' => false]);
    }

    private function token(array $form): array
    {
        $res = Http::asForm()->acceptJson()->withBasicAuth((string) $this->cfg('client_id'), (string) $this->cfg('client_secret'))
            ->post($this->cfg('token_url'), $form);
        if ($res->failed() || ! $res->json('access_token')) {
            throw new ErpException('QuickBooks token request failed: '.($res->json('error_description') ?? $res->json('error') ?? "HTTP {$res->status()}"),
                retryable: $res->serverError(), response: (array) $res->json(), httpStatus: $res->status());
        }

        return (array) $res->json();
    }

    private function credentials(array $tokens, string $realmId): array
    {
        return [
            'realm_id' => $realmId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->toIso8601String(),
            'refresh_expires_at' => now()->addSeconds((int) ($tokens['x_refresh_token_expires_in'] ?? 8640000))->toIso8601String(),
        ];
    }
}
