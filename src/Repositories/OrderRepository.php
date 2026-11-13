<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderRepository
{
    private const SORT_COLUMNS = [
        'required_date' => 'orders.required_date',
        'created_at' => 'orders.created_at',
        'total' => 'orders.total_amount',
    ];


    public function __construct(private readonly PDO $connection)
    {
    }

    public function lockForEdit(int $orderId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM orders WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        return $order === false ? null : $order;
    }

    public function customerEdits(int $orderId): array
    {
        $q = $this->connection->prepare('SELECT created_at,previous_total,new_total FROM customer_order_edits WHERE order_id=:id ORDER BY id DESC');
        $q->execute(['id' => $orderId]);
        return $q->fetchAll();
    }

    public function findCustomer(int $customerId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT customers.id, CONCAT(customers.first_name, ' ', customers.last_name) AS customer_name,
                customers.email AS customer_email, customers.phone_number AS customer_phone,
                address.street_address, address.delegation, address.governorate, address.postal_code
             FROM customers_accounts AS customers
             LEFT JOIN customer_addresses AS address ON address.id = (
                 SELECT id FROM customer_addresses WHERE customer_id = customers.id ORDER BY is_primary DESC, id ASC LIMIT 1
             ) WHERE customers.id = :id AND customers.account_status = 'ACTIVE'"
        );
        $statement->execute(['id' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function searchCustomers(string $search): array
    {
        $statement = $this->connection->prepare(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS customer_name, email AS customer_email, phone_number AS customer_phone
             FROM customers_accounts WHERE account_status = 'ACTIVE' AND (LOWER(CONCAT(first_name, ' ', last_name)) COLLATE \"C\" LIKE LOWER(:name) ESCAPE '='
                OR LOWER(email) COLLATE \"C\" LIKE LOWER(:email) ESCAPE '=' OR phone_number COLLATE \"C\" LIKE :phone ESCAPE '=')
             ORDER BY last_name, first_name, id LIMIT 20"
        );
        $term = '%' . SqlFragments::escapeLike($search) . '%';
        $statement->execute(['name' => $term, 'email' => $term, 'phone' => $term]);
        return $statement->fetchAll();
    }

    public function lockIncludingTrash(int $orderId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $orderId]);
        return $statement->fetch() ?: null;
    }

    public function setTrashed(int $orderId, bool $deleted, int $administratorId): void
    {
        $statement = $this->connection->prepare('UPDATE orders SET deleted_at = :deleted_at, deleted_by_administrator_id = :administrator WHERE id = :id');
        $statement->execute(['deleted_at' => $deleted ? date('Y-m-d H:i:s') : null,
            'administrator' => $deleted ? $administratorId : null, 'id' => $orderId]);
    }

    public function trashPage(int $page): array
    {
        $count = (int) $this->connection->query('SELECT COUNT(*) FROM orders WHERE deleted_at IS NOT NULL')->fetchColumn();
        $pages = max(1, (int) ceil($count / 20));
        $page = min(max(1, $page), $pages);
        $statement = $this->connection->prepare('SELECT id, order_number, customer_name_snapshot, order_status, deleted_at FROM orders WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC LIMIT 20 OFFSET :offset');
        $statement->bindValue('offset', ($page - 1) * 20, PDO::PARAM_INT);
        $statement->execute();
        return ['orders' => $statement->fetchAll(), 'page' => $page, 'pages' => $pages, 'total' => $count];
    }

    /** Update only the fields owned by the order details form. */
    public function updateDetails(int $orderId, array $values): void
    {
        $statement = $this->connection->prepare(
            'UPDATE orders SET customer_id = :customer_id, customer_name_snapshot = :customer_name,
                customer_email_snapshot = :customer_email, customer_phone_snapshot = :customer_phone,
                street_address_snapshot = :street_address, delegation_snapshot = :delegation,
                governorate_snapshot = :governorate, postal_code_snapshot = :postal_code,
                delivery_method = :delivery_method, required_date = :required_date,
                delivery_fee = :delivery_fee, total_amount = :total_amount,
                required_deposit_amount = :required_deposit_amount,
                customer_notes = :customer_notes, administrator_notes = :administrator_notes,
                updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute($values + ['id' => $orderId]);
    }


    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM orders {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(
        array $criteria,
        string $sort,
        string $direction,
        int $offset,
        int $limit
    ): array {
        [$where, $parameters] = $this->listWhere($criteria);
        $sortColumn = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['required_date'];
        $sortDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $statement = $this->connection->prepare(
            "SELECT orders.id, orders.order_number, orders.customer_id, orders.customer_name_snapshot,
                    orders.order_status, orders.order_source, orders.delivery_method,
                    orders.required_date, orders.total_amount, orders.created_at,
                    orders.required_deposit_amount,
                    (SELECT COUNT(*) FROM payments
                        WHERE payments.order_id = orders.id AND payments.payment_status = 'PENDING_VERIFICATION') AS pending_payment_count,
                    COALESCE((SELECT SUM(payments.amount) FROM payments
                        WHERE payments.order_id = orders.id AND payments.payment_status = 'VERIFIED'), 0.000) AS amount_paid
             FROM orders
             {$where}
             ORDER BY {$sortColumn} {$sortDirection}, orders.id {$sortDirection}
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    public function findSummary(int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT orders.id, orders.order_number, orders.customer_id,
                    orders.language_code, orders.order_source, orders.order_status,
                    orders.delivery_method, orders.required_date,
                    orders.customer_name_snapshot, orders.customer_email_snapshot,
                    orders.customer_phone_snapshot, orders.street_address_snapshot,
                    orders.delegation_snapshot, orders.governorate_snapshot,
                    orders.postal_code_snapshot, orders.subtotal, orders.delivery_fee,
                    orders.total_amount, orders.required_deposit_amount, orders.promo_code_snapshot, orders.promo_type_snapshot, orders.promo_value_snapshot, orders.promo_discount_amount, orders.manual_discount_type, orders.manual_discount_value, orders.manual_discount_reason, orders.manual_discount_amount, orders.quantity_savings_snapshot,
                    orders.price_locked_at, orders.deleted_at, orders.customer_notes,
                    orders.administrator_notes, orders.created_at, orders.updated_at,
                    COALESCE(payment_totals.amount_paid, 0.000) AS amount_paid,
                    COALESCE(payment_totals.pending_amount, 0.000) AS pending_amount,
                    COALESCE(payment_totals.rejected_amount, 0.000) AS rejected_amount
             FROM orders
             LEFT JOIN (
                 SELECT order_id,
                     SUM(CASE WHEN payment_status = 'VERIFIED' THEN amount ELSE 0 END) AS amount_paid,
                     SUM(CASE WHEN payment_status = 'PENDING_VERIFICATION' THEN amount ELSE 0 END) AS pending_amount,
                     SUM(CASE WHEN payment_status = 'REJECTED' THEN amount ELSE 0 END) AS rejected_amount
                 FROM payments
                 GROUP BY order_id
             ) AS payment_totals ON payment_totals.order_id = orders.id
             WHERE orders.id = :order_id AND orders.deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }


    public function findItems(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, product_id, product_name_snapshot, color_name_snapshot,
                    scent_name_snapshot, quantity, unit_price_snapshot,
                    unit_cost_snapshot, line_total_snapshot, customization_notes, catalogue_unit_price_snapshot, quantity_unit_price_snapshot, quantity_tier_snapshot, price_override_reason, recipe_source
             FROM order_items
             WHERE order_id = :order_id
             ORDER BY id ASC'
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchAll();
    }


    public function findPayments(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, payment_method, payment_type, payment_status, amount,
                    currency, customer_declared_paid_at, verified_at, created_at
             FROM payments
             WHERE order_id = :order_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchAll();
    }


    public function findStatusHistory(int $orderId): array
    {
        $statement = $this->connection->prepare(
            "SELECT history.id, history.previous_status, history.new_status,
                    history.change_source, history.change_note, history.changed_at,
                    CASE
                        WHEN administrators.id IS NULL THEN NULL
                        ELSE CONCAT(administrators.first_name, ' ', administrators.last_name)
                    END AS administrator_name
             FROM order_status_history AS history
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = history.changed_by_administrator_id
             WHERE history.order_id = :order_id
             ORDER BY history.changed_at ASC, history.id ASC"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $order */
    public function insertManualOrder(array $order): int
    {
        $this->assertDailyOrderLimit($order['customer_id'] ?? null);
        $statement = $this->connection->prepare(
            "INSERT INTO orders
                (order_number, customer_id, created_by_administrator_id, language_code, order_source,
                 order_status, delivery_method, required_date, customer_name_snapshot,
                 customer_email_snapshot, customer_phone_snapshot, street_address_snapshot,
                 delegation_snapshot, governorate_snapshot, postal_code_snapshot, subtotal,
                 delivery_fee, total_amount, required_deposit_amount, price_locked_at,
                 customer_notes, administrator_notes, promo_code_snapshot, promo_type_snapshot, promo_value_snapshot, promo_discount_amount, manual_discount_type, manual_discount_value, manual_discount_reason, manual_discount_amount, quantity_savings_snapshot)
             VALUES
                (:order_number, :customer_id, :administrator_id, 'en', 'MANUAL', 'REQUESTED',
                 :delivery_method, :required_date, :customer_name, :customer_email, :customer_phone,
                 :street_address, :delegation, :governorate, :postal_code, :subtotal, :delivery_fee,
                 :total_amount, :required_deposit, NOW(), :customer_notes, :administrator_notes, :promo_code_snapshot, :promo_type_snapshot, :promo_value_snapshot, :promo_discount_amount, :manual_discount_type, :manual_discount_value, :manual_discount_reason, :manual_discount_amount, :quantity_savings_snapshot)"
        );
        $statement->execute($order + ['customer_id' => null] + ['promo_code_snapshot' => null, 'promo_type_snapshot' => null, 'promo_value_snapshot' => null, 'promo_discount_amount' => '0.000', 'manual_discount_type' => null, 'manual_discount_value' => null, 'manual_discount_reason' => null, 'manual_discount_amount' => '0.000', 'quantity_savings_snapshot' => '0.000']);

        return (int) $this->connection->lastInsertId();
    }


    public function insertManualOrderItem(array $item): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO order_items
                (order_id, product_id, product_name_snapshot, selected_color_id, color_name_snapshot,
                 selected_scent_id, scent_name_snapshot, quantity, unit_price_snapshot,
                 unit_cost_snapshot, line_total_snapshot, customization_notes, catalogue_unit_price_snapshot, quantity_unit_price_snapshot, quantity_tier_snapshot, price_override_reason, recipe_source)
             VALUES
                (:order_id, :product_id, :product_name, :color_id, :color_name,
                 :scent_id, :scent_name, :quantity, :unit_price,
                 :unit_cost, :line_total, :customization_notes, :catalogue_unit_price_snapshot, :quantity_unit_price_snapshot, :quantity_tier_snapshot, :price_override_reason, :recipe_source)'
        );
        $statement->execute($item + [
            'product_id' => null,
            'color_id' => null,
            'color_name' => null,
            'scent_id' => null,
            'scent_name' => null,
            'unit_cost' => null, 'catalogue_unit_price_snapshot' => null, 'quantity_unit_price_snapshot' => null, 'quantity_tier_snapshot' => null, 'price_override_reason' => null, 'recipe_source' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function insertManualOrderHistory(int $orderId, int $administratorId): void
    {
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status, change_source, changed_by_administrator_id, change_note)
             VALUES (:order_id, NULL, 'REQUESTED', 'ADMINISTRATOR', :administrator_id, 'Manual order created by an administrator.')"
        );
        $statement->execute(['order_id' => $orderId, 'administrator_id' => $administratorId]);
    }


    public function insertCartOrder(array $order): int
    {
        $this->assertDailyOrderLimit($order['customer_id'] ?? null);
        $statement = $this->connection->prepare(
            "INSERT INTO orders
                (order_number, customer_id, created_by_administrator_id, language_code, order_source,
                 order_status, delivery_method, required_date, customer_name_snapshot,
                 customer_email_snapshot, customer_phone_snapshot, street_address_snapshot,
                 delegation_snapshot, governorate_snapshot, postal_code_snapshot, subtotal,
                 delivery_fee, total_amount, required_deposit_amount, price_locked_at,
                 customer_notes, administrator_notes)
             VALUES
                (:order_number, :customer_id, NULL, :language_code, 'CART', 'REQUESTED',
                 :delivery_method, :required_date, :customer_name, :customer_email, :customer_phone,
                 :street_address, :delegation, :governorate, :postal_code, :subtotal, :delivery_fee,
                 :total_amount, :required_deposit, NULL, :customer_notes, NULL)"
        );
        $statement->execute($order);

        return (int) $this->connection->lastInsertId();
    }

    /** All callers insert within a transaction; the account lock serializes the daily quota. */
    public function assertDailyOrderLimit(?int $customerId, ?\DateTimeImmutable $now = null): void
    {
        if ($customerId === null) {
            return;
        }
        $postgres = $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $q = $this->connection->prepare('SELECT id FROM customers_accounts WHERE id=:id' . ($postgres ? ' FOR UPDATE' : ''));
        $q->execute(['id' => $customerId]);
        $date = \App\Services\BusinessClock::today($now)->format('Y-m-d');
        $bounds = \App\Services\BusinessClock::utcBounds($date, $date);
        $q = $this->connection->prepare('SELECT COUNT(*) FROM orders WHERE customer_id=:id AND created_at>=:day_start AND created_at<:day_end');
        $q->execute(['id' => $customerId,'day_start' => $bounds['start'],'day_end' => $bounds['end']]);
        if ((int)$q->fetchColumn() >= 3) {
            throw new \DomainException('You may place at most three orders per day. Please try again tomorrow.');
        }
    }


    public function insertCartOrderItem(array $item): void
    {
        //            Native prepares cannot reuse a placeholder, hence :cost_product_id.
        $statement = $this->connection->prepare(
            'INSERT INTO order_items
                (order_id, product_id, product_name_snapshot, selected_color_id, color_name_snapshot,
                 selected_scent_id, scent_name_snapshot, quantity, unit_price_snapshot,
                 unit_cost_snapshot, line_total_snapshot, customization_notes)
             VALUES
                (:order_id, :product_id, :product_name, :color_id, :color_name,
                 :scent_id, :scent_name, :quantity, :unit_price,
                 (SELECT actual_cost FROM products WHERE id = :cost_product_id), :line_total, NULL)'
        );
        $statement->execute($item + ['cost_product_id' => $item['product_id']]);
    }


    public function insertCustomerOrderHistory(int $orderId): void
    {
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status, change_source, changed_by_administrator_id, change_note)
             VALUES (:order_id, NULL, 'REQUESTED', 'CUSTOMER', NULL, 'Order placed by the customer at checkout.')"
        );
        $statement->execute(['order_id' => $orderId]);
    }


    private function listWhere(array $criteria): array
    {
        $conditions = ['orders.deleted_at IS NULL'];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            $conditions[] = "(LOWER(orders.order_number) COLLATE \"C\" LIKE LOWER(:search_order_number) ESCAPE '='
                OR LOWER(orders.customer_name_snapshot) COLLATE \"C\" LIKE LOWER(:search_customer_name) ESCAPE '=')";
            $escapedSearch = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $parameters['search_order_number'] = $escapedSearch;
            $parameters['search_customer_name'] = $escapedSearch;
        }
        if (($criteria['status'] ?? null) === 'INCOMPLETE') {
            $conditions[] = "orders.order_status NOT IN ('PICKED_UP', 'DELIVERED', 'CANCELLED')";
        } elseif (($criteria['status'] ?? null) !== null) {
            $conditions[] = 'orders.order_status = :order_status';
            $parameters['order_status'] = (string) $criteria['status'];
        }
        if (($criteria['source'] ?? null) !== null) {
            $conditions[] = 'orders.order_source = :order_source';
            $parameters['order_source'] = (string) $criteria['source'];
        }
        if (($criteria['method'] ?? null) !== null) {
            $conditions[] = 'orders.delivery_method = :delivery_method';
            $parameters['delivery_method'] = (string) $criteria['method'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'orders.required_date >= :date_from';
            $parameters['date_from'] = (string) $criteria['date_from'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'orders.required_date <= :date_to';
            $parameters['date_to'] = (string) $criteria['date_to'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }


}
