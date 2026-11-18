<?php

namespace App\Services\Erp;

use App\Models\ErpConnection;
use App\Services\Erp\Drivers\MockConnector;
use App\Services\Erp\Drivers\OdooConnector;
use App\Services\Erp\Drivers\QuickBooksConnector;
use App\Services\Erp\Drivers\WebhookConnector;
use Closure;
use InvalidArgumentException;

/**
 * Builds connectors for a company. The connector is the company's active connection
 * (ERP Sync page), falling back to ERP_DRIVER; credentials come from .env plus, for
 * QuickBooks, the OAuth tokens stored on the connection.
 */
class ErpManager
{
    /** @var array<string, Closure(array, ErpContext): ErpConnector> */
    private array $custom = [];

    /** Register a connector (tests use this for fakes). */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    public function forCompany(int $companyId, ?string $connector = null): ErpConnector
    {
        $connection = $connector ? ErpConnection::for($companyId, $connector) : ErpConnection::activeFor($companyId);

        return $this->make($connection->connector, new ErpContext($companyId, $connection));
    }

    public function make(string $name, ErpContext $ctx): ErpConnector
    {
        $config = (array) config("extractiq.erp.drivers.$name", []);
        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($config, $ctx);
        }

        return match ($name) {
            'mock' => new MockConnector($config, $ctx),
            'odoo' => new OdooConnector($config),
            'quickbooks' => new QuickBooksConnector($config, $ctx),
            'webhook' => new WebhookConnector($config, $ctx),
            default => throw new InvalidArgumentException("Unknown ERP connector [$name]."),
        };
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_values(array_unique([...ErpConnection::CONNECTORS, ...array_keys($this->custom)]));
    }
}
