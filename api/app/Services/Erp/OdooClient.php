<?php

namespace App\Services\Erp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Minimal Odoo JSON-RPC client (/jsonrpc): common.login / common.version and object.execute_kw. */
class OdooClient
{
    private ?int $uid = null;

    public function __construct(private readonly array $config) {}

    public function url(string $path = ''): string
    {
        return rtrim((string) $this->config['url'], '/').$path;
    }

    public function database(): string
    {
        return (string) $this->config['database'];
    }

    public function version(): array
    {
        return (array) $this->rpc('common', 'version', []);
    }

    public function login(): int
    {
        if ($this->uid) {
            return $this->uid;
        }
        if (empty($this->config['password'])) {
            throw new ErpException('ODOO_PASSWORD is not set.', retryable: false);
        }
        $uid = $this->rpc('common', 'login', [$this->config['database'], $this->config['username'], $this->config['password']]);
        if (! $uid) {
            throw new ErpException('Odoo rejected the credentials.', retryable: false);
        }

        return $this->uid = (int) $uid;
    }

    public function execute(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        return $this->rpc('object', 'execute_kw', [
            $this->config['database'], $this->login(), $this->config['password'], $model, $method, $args, $kwargs ?: new \stdClass,
        ]);
    }

    /** First id matching a domain, or null. */
    public function find(string $model, array $domain): ?int
    {
        $ids = $this->execute($model, 'search', [$domain], ['limit' => 1]);

        return $ids ? (int) $ids[0] : null;
    }

    public function rpc(string $service, string $method, array $args): mixed
    {
        $payload = ['jsonrpc' => '2.0', 'method' => 'call', 'id' => random_int(1, PHP_INT_MAX), 'params' => ['service' => $service, 'method' => $method, 'args' => $args]];
        try {
            $response = Http::timeout(60)->post($this->url('/jsonrpc'), $payload);
        } catch (ConnectionException $e) {
            throw new ErpException('Odoo unreachable: '.$e->getMessage());
        }
        if ($response->failed()) {
            throw new ErpException("Odoo HTTP {$response->status()}", retryable: $response->serverError(), httpStatus: $response->status());
        }
        if ($error = $response->json('error')) {
            // Odoo reports business errors (validation, access, missing model) as JSON-RPC errors on HTTP 200: not retryable.
            throw new ErpException('Odoo: '.data_get($error, 'data.message', data_get($error, 'message', 'error')), retryable: false, response: (array) $error, httpStatus: 200);
        }

        return $response->json('result');
    }
}
