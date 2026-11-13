<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\CatalogueCategory;
use PDO;

final class CustomRequestRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function assertComponentAvailability(array $lines): void
    {
        $transactions = new \App\Database\TransactionManager($this->connection);
        (new \App\Services\AccessoryStockService(new AccessoryStockRepository($this->connection), $transactions))->assertAvailable($lines);
    }

    public function componentOptions(): array
    {
        return (new OrderProductionRepository($this->connection))->findOrderableProducts();
    }

    public function optionChoices(string $kind): array
    {
        return (new TaxonomyRepository($this->connection))->findActiveOptions($kind);
    }

    public function productTypes(): array
    {
        return (new TaxonomyRepository($this->connection))->findActiveOptions('product_types');
    }

    public function findRequestProducts(int $id): array
    {
        $q = $this->connection->prepare("SELECT cp.*, c.slug AS category_slug, COALESCE(ct.name,c.slug) AS category_name,
            COALESCE(tt.name,t.slug) AS type_name FROM custom_request_products cp
            JOIN categories c ON c.id=cp.category_id JOIN product_types t ON t.id=cp.product_type_id
            LEFT JOIN category_translations ct ON ct.category_id=c.id AND ct.language_code='en'
            LEFT JOIN product_type_translations tt ON tt.product_type_id=t.id AND tt.language_code='en'
            WHERE cp.request_id=:id ORDER BY cp.position");
        $q->execute(['id' => $id]);
        $products = $q->fetchAll();
        $children = $this->connection->prepare('SELECT c.*,category.slug AS component_category_slug FROM custom_request_components c LEFT JOIN products component ON component.id=c.product_id LEFT JOIN categories category ON category.id=component.category_id JOIN custom_request_products p ON p.id=c.request_product_id WHERE p.request_id=:id ORDER BY c.position');
        $children->execute(['id' => $id]);
        $components = [];
        foreach ($children->fetchAll() as $child) {
            $components[(int)$child['request_product_id']][] = $child;
        }
        foreach ($products as &$product) {
            $product['components'] = $components[(int)$product['id']] ?? [];
        }
        return $products;
    }

    public function insertRequestProduct(int $id, int $position, array $product): void
    {
        $q = $this->connection->prepare('INSERT INTO custom_request_products
            (request_id,position,working_name,category_id,product_type_id,requested_quantity,color_preferences,scent_preferences,specifications)
            VALUES (:request,:position,:working_name,:category_id,:product_type_id,:requested_quantity,:color_preferences,:scent_preferences,:specifications)');
        $components = $product['components'] ?? [];
        unset($product['components']);
        $optionData = array_intersect_key($product, array_flip(['color_id','scent_id','color_mode','scent_mode']));
        $product = array_diff_key($product, $optionData);
        $q->execute(['request' => $id,'position' => $position] + $product);
        $definition = (int)$this->connection->lastInsertId();
        if ($optionData !== []) {
            $q = $this->connection->prepare('UPDATE custom_request_products SET color_id=:color_id,scent_id=:scent_id,color_mode=:color_mode,scent_mode=:scent_mode WHERE id=:id');
            $q->execute(['id' => $definition] + $optionData);
        }
        foreach ($components as $index => $component) {
            $q = $this->connection->prepare('INSERT INTO custom_request_components (request_product_id,position,source,product_id,quantity,product_name_snapshot,unit_cost_snapshot,color_id,scent_id,color_mode,scent_mode,color_value,scent_value,specifications)
                VALUES (:parent,:position,:source,:product_id,:quantity,:product_name_snapshot,:unit_cost_snapshot,:color_id,:scent_id,:color_mode,:scent_mode,:color_value,:scent_value,:specifications)');
            $q->execute(['parent' => $definition,'position' => $index + 1] + $component);
        }
    }

    public function linkRequestItem(int $definition, int $item): void
    {
        $q = $this->connection->prepare('UPDATE custom_request_products SET converted_order_item_id=:item WHERE id=:id AND converted_order_item_id IS NULL');
        $q->execute(['item' => $item,'id' => $definition]);
        if ($q->rowCount() !== 1) {
            throw new \DomainException('This custom product has already been converted.');
        }
    }

    /** Called only under the parent request lock before agreement/conversion. */
    public function replaceBriefProducts(int $requestId, array $definitions): void
    {
        $query = $this->connection->prepare('DELETE FROM custom_request_components WHERE request_product_id IN (SELECT id FROM custom_request_products WHERE request_id=:id)');
        $query->execute(['id' => $requestId]);
        $query = $this->connection->prepare('DELETE FROM custom_request_products WHERE request_id=:id AND converted_order_item_id IS NULL AND draft_product_id IS NULL');
        $query->execute(['id' => $requestId]);
        foreach ($definitions as $index => $definition) {
            $this->insertRequestProduct($requestId, $index + 1, $definition);
        }
        if ($definitions !== []) {
            $query = $this->connection->prepare('UPDATE custom_product_requests SET category_id=:category WHERE id=:id');
            $query->execute(['category' => $definitions[0]['category_id'], 'id' => $requestId]);
        }
    }

    public function recordBriefRevision(int $requestId, int $actor, string $reason, array $before, array $after): void
    {
        $query = $this->connection->prepare('INSERT INTO custom_request_brief_revisions(request_id,administrator_id,reason,before_brief,after_brief) VALUES(:id,:actor,:reason,CAST(:before AS jsonb),CAST(:after AS jsonb))');
        $query->execute(['id' => $requestId, 'actor' => $actor, 'reason' => $reason,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR)]);
    }

    public function briefRevisions(int $requestId, int $page = 1): array
    {
        $offset = (max(1, $page) - 1) * 3;
        $query = $this->connection->prepare("SELECT r.*, CONCAT(a.first_name,' ',a.last_name) AS administrator_name FROM custom_request_brief_revisions r JOIN administrators_accounts a ON a.id=r.administrator_id WHERE request_id=:id ORDER BY r.id DESC LIMIT 3 OFFSET {$offset}");
        $query->execute(['id' => $requestId]);
        return $query->fetchAll();
    }

    public function briefRevisionCount(int $requestId): int
    {
        $query = $this->connection->prepare('SELECT COUNT(*) FROM custom_request_brief_revisions WHERE request_id=:id');
        $query->execute(['id' => $requestId]);
        return (int) $query->fetchColumn();
    }

    public function productionCompleted(int $order): bool
    {
        $q = $this->connection->prepare("SELECT 1 FROM orders o WHERE o.id=:id AND o.deleted_at IS NULL
            AND EXISTS (SELECT 1 FROM order_status_history h WHERE h.order_id=o.id AND h.new_status='PREPARED')");
        $q->execute(['id' => $order]);
        return $q->fetchColumn() !== false;
    }

    /** Called only while the parent request is locked and production completion is checked. */
    public function createCatalogueDraft(array $definition): int
    {
        if ($definition['draft_product_id'] !== null) {
            return (int) $definition['draft_product_id'];
        }
        $q = $this->connection->prepare('SELECT material_id,quantity_required FROM order_item_materials WHERE order_item_id=:id ORDER BY material_id');
        $q->execute(['id' => $definition['converted_order_item_id']]);
        $recipe = $q->fetchAll();
        if ($recipe === []) {
            throw new \DomainException('Complete every custom product production recipe before creating catalogue drafts.');
        }
        $products = new AdminProductRepository($this->connection);
        $id = $products->insertCore(['category_id' => $definition['category_id'],'product_type_id' => $definition['product_type_id'],
            'pricing_reference_product_id' => null,'slug' => 'custom-request-product-' . $definition['id'],
            'stock_quantity' => $definition['category_slug'] === CatalogueCategory::ACCESSORIES ? 0 : null,'candle_count' => null,'minimum_quantity' => $definition['category_slug'] === CatalogueCategory::EVENT_FAVORS ? 10 : 1,
            'purchase_mode' => 'CART','is_made_to_order' => 1,'can_purchase_alone' => 1]);
        $products->upsertTranslation($id, 'en', (string)$definition['working_name'], (string)$definition['specifications']);
        // Set composition remains references to the original records; duplicates aggregate by ID.
        if ($definition['category_slug'] === CatalogueCategory::SETS) {
            $counts = [];
            $nested = array_filter($definition['components'] ?? [], static fn (array $component): bool => ($component['component_category_slug'] ?? '') === CatalogueCategory::SETS);
            // Catalogue forbids nested sets. Retain those references on the request for explicit draft completion.
            foreach ($nested !== [] ? [] : ($definition['components'] ?? []) as $component) {
                if ($component['product_id'] !== null) {
                    $componentId = (int)$component['product_id'];
                    $counts[$componentId] = ($counts[$componentId] ?? 0) + (int)$component['quantity'];
                }
            }
            foreach ($counts as $componentId => $quantity) {
                if ($quantity <= 1000) {
                    $products->addComponent($id, $componentId, $quantity);
                }
            }
        }
        $recipes = new ProductRecipeRepository($this->connection);
        $referencedSet = $definition['category_slug'] === CatalogueCategory::SETS && array_filter($definition['components'] ?? [], static fn (array $component): bool => $component['product_id'] !== null) !== [];
        // The set engine already expands referenced components; copying the full aggregate would count them twice.
        foreach ($referencedSet ? [] : $recipe as $line) {
            $recipes->insert($id, (int)$line['material_id'], 'ACTUAL', (string)$line['quantity_required']);
        }
        $q = $this->connection->prepare('UPDATE custom_request_products SET draft_product_id=:product WHERE id=:id');
        $q->execute(['product' => $id,'id' => $definition['id']]);
        return $id;
    }


    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM custom_product_requests AS requests {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(array $criteria, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT requests.id, requests.request_number, requests.customer_name_snapshot,
                    requests.request_status, requests.required_date, requests.created_at,
                    COALESCE(category_text.name, 'Untranslated category') AS category_name,
                    CASE WHEN administrators.id IS NULL THEN NULL
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name,
                    (SELECT attempts.next_follow_up_at
                     FROM request_contact_attempts AS attempts
                     WHERE attempts.request_id = requests.id
                     ORDER BY attempts.contacted_at DESC, attempts.id DESC LIMIT 1) AS next_follow_up_at
             FROM custom_product_requests AS requests
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = requests.assigned_administrator_id
             {$where}
             ORDER BY CASE requests.request_status
                        WHEN 'SUBMITTED' THEN 1 WHEN 'CONTACTED' THEN 2 WHEN 'DISCUSSING' THEN 3
                        WHEN 'AGREED' THEN 4 WHEN 'CONVERTED' THEN 5 WHEN 'DECLINED' THEN 6
                        WHEN 'CANCELLED' THEN 7 ELSE 0 END,
                      requests.required_date IS NULL, requests.required_date ASC, requests.created_at ASC, requests.id ASC
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    public function findSummary(int $requestId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT requests.*,
                    categories.slug AS category_slug, products.minimum_quantity AS product_minimum_quantity,
                    COALESCE(category_text.name, 'Untranslated category') AS category_name,
                    product_text.name AS product_name,
                    CASE WHEN administrators.id IS NULL THEN NULL
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name,
                    orders.order_number AS converted_order_number
             FROM custom_product_requests AS requests
             LEFT JOIN categories ON categories.id = requests.category_id
             LEFT JOIN products ON products.id = requests.product_id
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = requests.product_id AND product_text.language_code = 'en'
             LEFT JOIN administrators_accounts AS administrators
               ON administrators.id = requests.assigned_administrator_id
             LEFT JOIN orders ON orders.id = requests.converted_order_id
             WHERE requests.id = :request_id LIMIT 1"
        );
        $statement->execute(['request_id' => $requestId]);
        $request = $statement->fetch();

        return $request === false ? null : $request;
    }


    public function findContactHistory(int $requestId): array
    {
        $statement = $this->connection->prepare(
            "SELECT attempts.contact_method, attempts.contact_outcome, attempts.contact_notes,
                    attempts.contacted_at, attempts.next_follow_up_at,
                    CASE WHEN administrators.id IS NULL THEN 'Former administrator'
                         ELSE CONCAT(administrators.first_name, ' ', administrators.last_name) END AS administrator_name
             FROM request_contact_attempts AS attempts
             LEFT JOIN administrators_accounts AS administrators ON administrators.id = attempts.administrator_id
             WHERE attempts.request_id = :request_id
             ORDER BY attempts.contacted_at DESC, attempts.id DESC"
        );
        $statement->execute(['request_id' => $requestId]);

        return $statement->fetchAll();
    }


    public function findCategories(): array
    {
        $statement = $this->connection->query(
            "SELECT categories.id, COALESCE(category_text.name, 'Untranslated category') AS name
             FROM categories
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = categories.id AND category_text.language_code = 'en'
             ORDER BY name ASC, categories.id ASC"
        );

        return $statement->fetchAll();
    }


    public function findActiveAdministrators(): array
    {
        $statement = $this->connection->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name
             FROM administrators_accounts WHERE account_status = 'ACTIVE'
             ORDER BY first_name ASC, last_name ASC, id ASC"
        );

        return $statement->fetchAll();
    }


    public function findAdministratorsForFilter(): array
    {
        $statement = $this->connection->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name
             FROM administrators_accounts
             ORDER BY first_name ASC, last_name ASC, id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * Insert an enquiry recorded by an administrator.
     *
     * The repository deliberately receives already-validated snapshot values.
     * Validation stays in the service, while this class owns only the SQL.
     *
     * @param array<string, mixed> $request
     */
    public function insertAdministratorRequest(array $request): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO custom_product_requests
                (request_number, customer_id, category_id, product_id,
                 customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                 preferred_contact_method, budget, requested_quantity, required_date,
                 color_preferences, scent_preferences, packaging_preferences, customer_message,
                 request_status, assigned_administrator_id)
             VALUES
                (:request_number, :customer_id, :category_id, NULL,
                 :customer_name, :customer_email, :customer_phone,
                 :contact_method, :budget, :quantity, :required_date,
                 :color_preferences, :scent_preferences, :packaging_preferences, :customer_message,
                 'SUBMITTED', :administrator_id)"
        );
        $statement->execute($request + ['customer_id' => null]);

        return (int) $this->connection->lastInsertId();
    }


    public function findPublicCategories(string $languageCode = 'en'): array
    {
        $statement = $this->connection->prepare(
            "SELECT categories.id, categories.slug,
                    COALESCE(NULLIF(category_text.name, ''), english.name) AS name
             FROM categories
             INNER JOIN category_translations AS english
               ON english.category_id = categories.id AND english.language_code = 'en'
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = categories.id AND category_text.language_code = :language_code
             WHERE categories.is_active = 1
             ORDER BY name ASC, categories.id ASC"
        );
        $statement->execute(['language_code' => $languageCode]);

        return $statement->fetchAll();
    }

    /**
     * Insert an enquiry submitted through the public contact form.
     *
     * WHAT: web requests start SUBMITTED and unassigned, with a REQ-W- number.
     * WHY:  they join the same administrator queue as phone/social enquiries
     *       (admin/requests), where someone picks them up and records contact.
     *
     * @param array<string, mixed> $request
     */
    public function insertWebRequest(array $request): int
    {
        $statement = $this->connection->prepare(
            "INSERT INTO custom_product_requests
                (request_number, customer_id, category_id, product_id,
                 customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                 preferred_contact_method, budget, requested_quantity, required_date,
                 color_preferences, scent_preferences, packaging_preferences, customer_message,
                 request_status, assigned_administrator_id)
             VALUES
                (:request_number, :customer_id, :category_id, :product_id,
                 :customer_name, :customer_email, :customer_phone,
                 :contact_method, :budget, :quantity, :required_date,
                 :color_preferences, :scent_preferences, :packaging_preferences, :customer_message,
                 'SUBMITTED', NULL)"
        );
        $statement->execute($request);

        return (int) $this->connection->lastInsertId();
    }


    public function countRecentWebRequests(int $windowSeconds, ?string $phone = null): int
    {
        $postgres = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $number = $postgres ? 'request_number COLLATE "C"' : 'request_number';
        $windowStart = $postgres
            ? "CURRENT_TIMESTAMP - (CAST(:window_seconds AS integer) * INTERVAL '1 second')"
            : "datetime(CURRENT_TIMESTAMP, '-' || :window_seconds || ' seconds')";
        $sql = "SELECT COUNT(*) FROM custom_product_requests
                WHERE {$number} LIKE 'REQ-W-%'
                  AND created_at >= {$windowStart}";
        $parameters = ['window_seconds' => max(1, $windowSeconds)];
        if ($phone !== null) {
            $sql .= ' AND customer_phone_snapshot = :phone';
            $parameters['phone'] = $phone;
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockRequest(int $requestId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT requests.*,
                    categories.slug AS category_slug, products.minimum_quantity AS product_minimum_quantity,
                    COALESCE(product_text.name, category_text.name, 'Custom product') AS suggested_product_name
             FROM custom_product_requests AS requests
             LEFT JOIN categories ON categories.id = requests.category_id
             LEFT JOIN products ON products.id = requests.product_id
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = requests.product_id AND product_text.language_code = 'en'
             LEFT JOIN category_translations AS category_text
               ON category_text.category_id = requests.category_id AND category_text.language_code = 'en'
             WHERE requests.id = :request_id LIMIT 1 FOR UPDATE OF requests"
        );
        $statement->execute(['request_id' => $requestId]);
        $request = $statement->fetch();

        return $request === false ? null : $request;
    }


    public function activeAdministratorExists(int $administratorId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM administrators_accounts WHERE id = :administrator_id AND account_status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['administrator_id' => $administratorId]);

        return $statement->fetchColumn() !== false;
    }


    public function assign(int $requestId, ?int $administratorId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE custom_product_requests SET assigned_administrator_id = :administrator_id WHERE id = :request_id'
        );
        $statement->bindValue('request_id', $requestId, PDO::PARAM_INT);
        $statement->bindValue('administrator_id', $administratorId, $administratorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();
    }


    public function updateStatus(int $requestId, string $status): void
    {
        $statement = $this->connection->prepare(
            'UPDATE custom_product_requests SET request_status = :request_status WHERE id = :request_id'
        );
        $statement->execute(['request_status' => $status, 'request_id' => $requestId]);
    }


    public function insertContactAttempt(
        int $requestId,
        int $administratorId,
        string $method,
        string $outcome,
        ?string $notes,
        ?string $followUp
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO request_contact_attempts
                (request_id, administrator_id, contact_method, contact_outcome, contact_notes, next_follow_up_at)
             VALUES (:request_id, :administrator_id, :contact_method, :contact_outcome, :contact_notes, :next_follow_up_at)'
        );
        $statement->execute([
            'request_id' => $requestId,
            'administrator_id' => $administratorId,
            'contact_method' => $method,
            'contact_outcome' => $outcome,
            'contact_notes' => $notes,
            'next_follow_up_at' => $followUp,
        ]);
    }


    public function insertManualOrder(array $order): int
    {
        (new OrderRepository($this->connection))->assertDailyOrderLimit(isset($order['customer_id']) ? (int)$order['customer_id'] : null);
        $statement = $this->connection->prepare(
            'INSERT INTO orders
                (order_number, customer_id, created_by_administrator_id, language_code, order_source,
                 order_status, delivery_method, required_date, customer_name_snapshot,
                 customer_email_snapshot, customer_phone_snapshot, street_address_snapshot,
                 delegation_snapshot, governorate_snapshot, postal_code_snapshot, subtotal,
                 delivery_fee, total_amount, required_deposit_amount, price_locked_at,
                 customer_notes, administrator_notes)
             VALUES
                (:order_number, :customer_id, :administrator_id, \'en\', \'MANUAL\', \'REQUESTED\',
                 :delivery_method, :required_date, :customer_name, :customer_email, :customer_phone,
                 :street_address, :delegation, :governorate, :postal_code, :subtotal, :delivery_fee,
                 :total_amount, :required_deposit, NOW(), :customer_notes, :administrator_notes)'
        );
        $statement->execute($order);

        return (int) $this->connection->lastInsertId();
    }


    public function insertOrderItem(array $item): int
    {
        $cost = $this->connection->prepare('SELECT actual_cost FROM products WHERE id = :id');
        $cost->execute(['id' => $item['product_id']]);
        return (new OrderRepository($this->connection))->insertManualOrderItem($item + [
            'unit_cost' => $cost->fetchColumn() ?: null,
            'color_id' => null, 'color_name' => null, 'scent_id' => null, 'scent_name' => null,
        ]);
    }


    public function insertInitialOrderHistory(int $orderId, int $administratorId): void
    {
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status, change_source, changed_by_administrator_id, change_note)
             VALUES (:order_id, NULL, 'REQUESTED', 'ADMINISTRATOR', :administrator_id, 'Created from an agreed custom request.')"
        );
        $statement->execute(['order_id' => $orderId, 'administrator_id' => $administratorId]);
    }

    public function markConverted(int $requestId, int $orderId): void
    {
        $statement = $this->connection->prepare(
            "UPDATE custom_product_requests
             SET request_status = 'CONVERTED', converted_order_id = :order_id, converted_at = NOW()
             WHERE id = :request_id AND request_status = 'AGREED' AND converted_order_id IS NULL"
        );
        $statement->execute(['order_id' => $orderId, 'request_id' => $requestId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The custom request conversion state changed unexpectedly.');
        }
    }


    private function listWhere(array $criteria): array
    {
        $conditions = [($criteria['status'] ?? null) === 'ARCHIVED' ? 'requests.archived_at IS NOT NULL' : 'requests.archived_at IS NULL'];
        $parameters = [];
        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(LOWER(requests.request_number) COLLATE \"C\" LIKE LOWER(:search_number) ESCAPE '='
                OR LOWER(requests.customer_name_snapshot) COLLATE \"C\" LIKE LOWER(:search_name) ESCAPE '=')";
            $parameters['search_number'] = $escaped;
            $parameters['search_name'] = $escaped;
        }
        if (($criteria['status'] ?? null) === 'OPEN') {
            $conditions[] = "requests.request_status IN ('SUBMITTED', 'CONTACTED', 'DISCUSSING', 'AGREED')";
        } elseif (($criteria['status'] ?? null) !== null && $criteria['status'] !== 'ARCHIVED') {
            $conditions[] = 'requests.request_status = :request_status';
            $parameters['request_status'] = (string) $criteria['status'];
        }
        if (($criteria['category'] ?? null) !== null) {
            $conditions[] = 'requests.category_id = :category_id';
            $parameters['category_id'] = (string) $criteria['category'];
        }
        if (($criteria['administrator'] ?? null) === 'unassigned') {
            $conditions[] = 'requests.assigned_administrator_id IS NULL';
        } elseif (($criteria['administrator'] ?? null) !== null) {
            $conditions[] = 'requests.assigned_administrator_id = :administrator_id';
            $parameters['administrator_id'] = (string) $criteria['administrator'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'requests.required_date >= :date_from';
            $parameters['date_from'] = (string) $criteria['date_from'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'requests.required_date <= :date_to';
            $parameters['date_to'] = (string) $criteria['date_to'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }


}
