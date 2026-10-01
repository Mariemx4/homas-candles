<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CustomerAccountReadRepository;

final class CustomerAccountController
{
    private const PAGE_SIZE = 10;

    public function __construct(private readonly CustomerAccountReadRepository $accounts)
    {
    }

    public function overview(int $customerId, array $query): array
    {
        $account = $this->accounts->activeAccount($customerId);
        if ($account === null) {
            return ['account' => null];
        }
        $total = $this->accounts->orderCount($customerId);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $requested = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = min($requested === false ? 1 : $requested, $pages);
        return [
            'account' => $account,
            'addresses' => $this->accounts->addresses($customerId),
            'orders' => $this->accounts->orders($customerId, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page,
            'pages' => $pages,
            'totalOrders' => $total,
        ];
    }

    public function order(int $customerId, mixed $rawId): array
    {
        $account = $this->accounts->activeAccount($customerId);
        if ($account === null) {
            return ['account' => null, 'order' => null, 'items' => []];
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $order = $id === false ? null : $this->accounts->order($customerId, $id);
        return [
            'account' => $account,
            'order' => $order,
            'items' => $order === null ? [] : $this->accounts->orderItems($customerId, $id),
        ];
    }
}
