<?php

namespace Damms005\LaravelMultipay\Services\Monnify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MonnifyApiClient
{
    public const LOGIN_PATH = 'api/v1/auth/login';

    public const INIT_TRANSACTION_PATH = 'api/v1/merchant/transactions/init-transaction';

    public const TRANSACTION_STATUS_PATH = 'api/v2/transactions';

    public const CHARGE_CARD_TOKEN_PATH = 'api/v1/merchant/cards/charge-card-token';

    public const MANDATE_CREATE_PATH = 'api/v1/direct-debit/mandate/create';

    public const MANDATE_STATUS_PATH = 'api/v1/direct-debit/mandate/status';

    public const MANDATE_DEBIT_PATH = 'api/v1/direct-debit/mandate/debit';

    public const MANDATE_DEBIT_STATUS_PATH = 'api/v1/direct-debit/mandate/debit/status';

    public const MANDATE_UPDATE_PATH = 'api/v1/direct-debit/mandate/update';

    protected const ACCESS_TOKEN_CACHE_KEY = 'laravel-multipay:monnify:access-token';

    public function __construct(
        protected string $apiKey,
        protected string $secretKey,
        protected string $contractCode,
        protected string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('laravel-multipay.monnify.api_key'),
            secretKey: (string) config('laravel-multipay.monnify.secret_key'),
            contractCode: (string) config('laravel-multipay.monnify.contract_code'),
            baseUrl: (string) (config('laravel-multipay.monnify.base_url') ?: 'https://sandbox.monnify.com'),
        );
    }

    public function contractCode(): string
    {
        return $this->contractCode;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    public function post(string $path, array $payload, string $context): array
    {
        return $this->send('post', $path, $payload, $context);
    }

    public function get(string $path, array $query, string $context): array
    {
        return $this->send('get', $path, $query, $context);
    }

    public function put(string $path, array $payload, string $context): array
    {
        return $this->send('put', $path, $payload, $context);
    }

    protected function send(string $method, string $path, array $payload, string $context): array
    {
        $response = $this->authenticatedRequest()->{$method}($path, $payload);

        if ($this->isExpiredTokenResponse($response)) {
            $this->forgetAccessToken();

            $response = $this->authenticatedRequest()->{$method}($path, $payload);
        }

        return $this->decode($response, $context);
    }

    protected function authenticatedRequest(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson();
    }

    public function accessToken(): string
    {
        if (! $this->cacheEnabled()) {
            return $this->requestAccessToken()['token'];
        }

        $cached = Cache::get($this->accessTokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        ['token' => $token, 'ttl' => $ttl] = $this->requestAccessToken();

        Cache::put($this->accessTokenCacheKey(), $token, $ttl);

        return $token;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->accessTokenCacheKey());
    }

    protected function requestAccessToken(): array
    {
        if (! $this->hasCredentials()) {
            throw new \Exception('Monnify API key and secret key are required. Set MONNIFY_API_KEY and MONNIFY_SECRET_KEY.');
        }

        $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->post(self::LOGIN_PATH);

        $body = $this->decode($response, 'authenticating with Monnify');

        $token = $body['responseBody']['accessToken'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new \Exception('Monnify did not return an access token.');
        }

        $expiresIn = (int) ($body['responseBody']['expiresIn'] ?? 0);
        $safetyMargin = (int) config('laravel-multipay.monnify.token_cache.safety_margin_seconds', 60);

        return [
            'token' => $token,
            'ttl' => max(30, $expiresIn - $safetyMargin),
        ];
    }

    protected function isExpiredTokenResponse(Response $response): bool
    {
        if ($response->status() === 401) {
            return true;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return false;
        }

        $message = strtolower((string) ($body['responseMessage'] ?? ''));

        return str_contains($message, 'expired') && str_contains($message, 'token');
    }

    protected function decode(Response $response, string $context): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new \Exception("Monnify returned an unreadable response while {$context}: {$response->body()}");
        }

        if ($response->failed() || ($body['requestSuccessful'] ?? false) !== true) {
            $message = $body['responseMessage'] ?? $response->body();
            $code = $body['responseCode'] ?? $response->status();

            throw new \Exception("Monnify error while {$context}: {$message} (responseCode: {$code})");
        }

        return $body;
    }

    protected function cacheEnabled(): bool
    {
        return (bool) config('laravel-multipay.monnify.token_cache.enabled', true);
    }

    protected function accessTokenCacheKey(): string
    {
        return self::ACCESS_TOKEN_CACHE_KEY . ':' . md5($this->baseUrl() . '|' . $this->apiKey);
    }
}
