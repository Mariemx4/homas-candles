<?php

declare(strict_types=1);

namespace App\Container;

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminProductRepository;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\CustomRequestRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ScentStockRepository;
use App\Services\AccessoryStockService;
use App\Services\CustomerModeService;
use App\Services\OrderProductionService;
use App\Services\PricingService;
use App\Services\ProductReadinessService;
use App\Services\ScentAvailabilityService;
use PDO;
use WeakMap;

/**
 * The building blocks that several bootstrap files need, built once per request.
 *
 * Bootstrap files (app/Bootstrap) wire each page's controllers. Before this
 * class, the same repositories and stock services were written out in up to
 * thirteen of them. Now a bootstrap asks for them here:
 *
 *     $shared = SharedServices::for($databaseConnection);
 *     $orderRepository = $shared->orderRepository();
 *
 * Every object here keeps no state of its own (only its constructor
 * dependencies), so one instance per database connection is safe to share.
 * Page-specific controllers and services stay in their bootstrap file.
 */
final class SharedServices
{
    /** @var WeakMap<PDO, self>|null one registry per connection object */
    private static ?WeakMap $registries = null;

    /** @var array<string, object> */
    private array $instances = [];

    private function __construct(private readonly PDO $connection)
    {
    }

    public static function for(PDO $connection): self
    {
        self::$registries ??= new WeakMap();

        return self::$registries[$connection] ??= new self($connection);
    }

    /** Runs multi-query changes so they all commit or all roll back. */
    public function transactionManager(): TransactionManager
    {
        return $this->shared(TransactionManager::class, fn () => new TransactionManager($this->connection));
    }

    public function orderRepository(): OrderRepository
    {
        return $this->shared(OrderRepository::class, fn () => new OrderRepository($this->connection));
    }

    public function orderProductionRepository(): OrderProductionRepository
    {
        return $this->shared(OrderProductionRepository::class, fn () => new OrderProductionRepository($this->connection));
    }

    /** Decides whether order items carry production data and stores custom recipes. */
    public function orderProductionService(): OrderProductionService
    {
        return $this->shared(OrderProductionService::class, fn () => new OrderProductionService(
            $this->orderProductionRepository(),
            $this->transactionManager()
        ));
    }

    public function customerAccountRepository(): CustomerAccountRepository
    {
        return $this->shared(CustomerAccountRepository::class, fn () => new CustomerAccountRepository(
            $this->connection,
            $this->transactionManager()
        ));
    }

    /** Existing / new / guest customer handling for orders and requests entered by staff. */
    public function customerModeService(): CustomerModeService
    {
        return $this->shared(CustomerModeService::class, fn () => new CustomerModeService(
            $this->orderRepository(),
            $this->customerAccountRepository()
        ));
    }

    public function customRequestRepository(): CustomRequestRepository
    {
        return $this->shared(CustomRequestRepository::class, fn () => new CustomRequestRepository($this->connection));
    }

    /** The one place that checks and moves accessory stock. */
    public function accessoryStockService(): AccessoryStockService
    {
        return $this->shared(AccessoryStockService::class, fn () => new AccessoryStockService(
            new AccessoryStockRepository($this->connection),
            $this->transactionManager()
        ));
    }

    /** Checks each scent against the stock of its fragrance material. */
    public function scentAvailabilityService(): ScentAvailabilityService
    {
        return $this->shared(ScentAvailabilityService::class, fn () => new ScentAvailabilityService(
            new ScentStockRepository($this->connection),
            $this->transactionManager()
        ));
    }

    public function paymentRepository(): PaymentRepository
    {
        return $this->shared(PaymentRepository::class, fn () => new PaymentRepository($this->connection));
    }

    public function materialStockRepository(): MaterialStockRepository
    {
        return $this->shared(MaterialStockRepository::class, fn () => new MaterialStockRepository(
            $this->connection,
            $this->transactionManager()
        ));
    }

    public function adminProductRepository(): AdminProductRepository
    {
        return $this->shared(AdminProductRepository::class, fn () => new AdminProductRepository($this->connection));
    }

    /** The single authority for catalogue selling prices. */
    public function pricingService(): PricingService
    {
        return $this->shared(PricingService::class, fn () => new PricingService(
            new PricingRepository($this->connection),
            $this->transactionManager()
        ));
    }

    /** The single authority for whether a product may be active. */
    public function productReadinessService(): ProductReadinessService
    {
        return $this->shared(ProductReadinessService::class, fn () => new ProductReadinessService(
            $this->adminProductRepository(),
            $this->pricingService(),
            $this->transactionManager()
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $key
     * @param callable(): T   $create
     * @return T
     */
    private function shared(string $key, callable $create): object
    {
        return $this->instances[$key] ??= $create();
    }
}
