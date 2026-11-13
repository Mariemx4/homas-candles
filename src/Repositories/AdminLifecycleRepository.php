<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use DomainException;

/**
 * Persistence for account/catalogue status management. The service chooses the
 * permitted action; this repository binds values and holds the required locks.
 * Callers must keep locking and mutation inside the same transaction.
 */
final class AdminLifecycleRepository
{
    public const TABLES = [
        'customers' => 'customers_accounts',
        'administrators' => 'administrators_accounts',
        'materials' => 'materials',
        'products' => 'products',
        'categories' => 'categories',
        'product_types' => 'product_types',
        'colors' => 'colors',
        'scents' => 'scents',
        'promotions' => 'promotion_codes',
        'requests' => 'custom_product_requests',
    ];
    private array $references = [];
    public function __construct(private readonly PDO $db)
    {
    }
    private function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw new DomainException('Unknown record type.');
    }
    public function find(string $kind, int $id, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->table($kind) . ' WHERE id=:id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }
    public function authorize(int $actor): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM administrators_accounts WHERE id=:id AND account_status='ACTIVE' FOR SHARE");
        $statement->execute(['id' => $actor]);
        return $statement->fetchColumn() !== false;
    }
    public function lockAdministrators(): void
    {
        $this->db->query('SELECT id FROM administrators_accounts ORDER BY id FOR UPDATE')->fetchAll();
    }
    public function activeAdministrators(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM administrators_accounts WHERE account_status='ACTIVE'")->fetchColumn();
    }
    public function administrators(): array
    {
        return $this->db->query('SELECT id,first_name,last_name,email,account_status FROM administrators_accounts ORDER BY first_name,last_name LIMIT 200')->fetchAll();
    }
    /** One aggregate statement on the confirmation screen, never one query per list row. Includes inactive/trash references. */
    public function dependencies(string $kind, int $id): array
    {
        $table = $this->table($kind);
        if (!isset($this->references[$table])) {
            $statement = $this->db->prepare("SELECT DISTINCT child.relname AS table_name,a.attname AS column_name
                FROM pg_constraint f JOIN pg_class child ON child.oid=f.conrelid
                JOIN pg_namespace ns ON ns.oid=child.relnamespace JOIN pg_attribute a ON a.attrelid=f.conrelid AND a.attnum=ANY(f.conkey)
                WHERE f.contype='f' AND f.confrelid=to_regclass(:parent) AND ns.nspname=current_schema() ORDER BY child.relname,a.attname");
            $statement->execute(['parent' => $table]);
            $this->references[$table] = $statement->fetchAll();
        }
        // Translation rows and reset tokens are owned metadata, not business history.
        $owned = ['category_translations','product_type_translations','color_translations','scent_translations','product_translations','customer_password_reset_codes'];
        $parts = [];
        $params = [];
        foreach ($this->references[$table] as $i => $ref) {
            if (in_array($ref['table_name'], $owned, true)) {
                continue;
            }
            $child = $ref['table_name'];
            $column = $ref['column_name'];
            if (!preg_match('/^[a-z_]+$/D', $child . $column)) {
                throw new DomainException('Dependency checks are unavailable.');
            }
            $parts[] = "SELECT '$child' AS source,COUNT(*) AS count FROM \"$child\" WHERE \"$column\"=:ref_$i";
            $params['ref_' . $i] = $id;
        }
        if ($kind === 'promotions') {
            $parts[] = 'SELECT \'orders\' AS source,COUNT(*) AS count FROM orders WHERE promo_code_snapshot=(SELECT code FROM promotion_codes WHERE id=:promo_id)';
            $params['promo_id'] = $id;
        }
        $counts = [];
        if ($parts) {
            $statement = $this->db->prepare(implode(' UNION ALL ', $parts));
            $statement->execute($params);
            foreach ($statement->fetchAll() as $row) {
                if ((int)$row['count'] > 0) {
                    $counts[$row['source']] = ($counts[$row['source']] ?? 0) + (int)$row['count'];
                }
            }
        }
        return $counts;
    }
    public function delete(string $kind, int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM ' . $this->table($kind) . ' WHERE id=:id');
        $statement->execute(['id' => $id]);
    }
    public function accountStatus(string $kind, int $id, bool $active): void
    {
        $statement = $this->db->prepare('UPDATE ' . $this->table($kind) . ' SET account_status=:state WHERE id=:id');
        $statement->execute(['state' => $active ? 'ACTIVE' : 'SUSPENDED','id' => $id]);
    }
    public function promotionStatus(int $id, bool $active): void
    {
        $statement = $this->db->prepare('UPDATE promotion_codes SET is_active=:active WHERE id=:id');
        $statement->execute(['active' => $active ? 1 : 0,'id' => $id]);
    }
    public function saveProfile(string $kind, ?int $id, array $values): int
    {
        if (!in_array($kind, ['customers','administrators'], true)) {
            throw new DomainException('Choose an account.');
        }
        $table = $this->table($kind);
        $columns = array_keys($values);
        $allowed = ['first_name','last_name','email','phone_number','second_phone_number','second_email','password_hash','account_status'];
        foreach ($columns as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new DomainException('Invalid profile field.');
            }
        }
        if ($id === null) {
            $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ') RETURNING id';
        } else {
            $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn ($column) => $column . '=:' . $column, $columns)) . ' WHERE id=:id RETURNING id';
            $values['id'] = $id;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($values);
        return (int)$statement->fetchColumn();
    }
    public function addresses(int $customer): array
    {
        $statement = $this->db->prepare('SELECT * FROM customer_addresses WHERE customer_id=:id ORDER BY is_primary DESC,id');
        $statement->execute(['id' => $customer]);
        return $statement->fetchAll();
    }

    /** Explicit allow lists exclude credentials and operational login timestamps. */
    public function accountSnapshot(string $kind, int $id): ?array
    {
        $profile = $this->find($kind, $id);
        if ($profile === null) {
            return null;
        }
        $allowed = array_flip(['id','first_name','last_name','email','phone_number','second_email','second_phone_number','account_status']);
        $state = array_intersect_key($profile, $allowed);
        if ($kind === 'customers') {
            $state['addresses'] = array_map(static fn (array $address): array => array_intersect_key($address, array_flip(['id','address_label','street_address','delegation','governorate','postal_code','is_primary'])), $this->addresses($id));
        }
        return $state;
    }

    public function recordAccountChange(string $kind, int $id, int $actor, bool $customerSelf, string $action, ?array $before, ?array $after): void
    {
        if ($before === $after) {
            return;
        }
        $person = $this->find($customerSelf ? 'customers' : 'administrators', $actor) ?? throw new DomainException('The change actor could not be found.');
        $statement = $this->db->prepare('INSERT INTO account_change_history(account_kind,account_id,actor_administrator_id,actor_customer_id,actor_name,action,before_state,after_state) VALUES(:kind,:id,:administrator,:customer,:name,:action,CAST(:before AS jsonb),CAST(:after AS jsonb))');
        $statement->execute(['kind' => $kind,'id' => $id,'administrator' => $customerSelf ? null : $actor,'customer' => $customerSelf ? $actor : null,
            'name' => trim($person['first_name'] . ' ' . $person['last_name']),'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR)]);
    }

    public function accountHistoryCount(string $kind, ?int $id): int
    {
        if (!in_array($kind, ['customers','administrators'], true)) {
            throw new DomainException('Choose an account type.');
        }
        $statement = $this->db->prepare('SELECT COUNT(*) FROM account_change_history WHERE account_kind=:kind' . ($id === null ? '' : ' AND account_id=:id'));
        $statement->execute($id === null ? ['kind' => $kind] : ['kind' => $kind,'id' => $id]);
        return (int)$statement->fetchColumn();
    }

    public function accountHistory(string $kind, ?int $id, int $limit = 20, int $offset = 0): array
    {
        if (!in_array($kind, ['customers','administrators'], true)) {
            throw new DomainException('Choose an account type.');
        }
        $statement = $this->db->prepare('SELECT * FROM account_change_history WHERE account_kind=:kind' . ($id === null ? '' : ' AND account_id=:id') . ' ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)) . ' OFFSET ' . max(0, $offset));
        $statement->execute($id === null ? ['kind' => $kind] : ['kind' => $kind,'id' => $id]);
        return $statement->fetchAll();
    }
    public function updateAddress(int $customer, int $id, array $values): void
    {
        $statement = $this->db->prepare('UPDATE customer_addresses SET address_label=:address_label,street_address=:street_address,delegation=:delegation,governorate=:governorate,postal_code=:postal_code WHERE customer_id=:customer AND id=:id');
        $statement->execute($values + ['customer' => $customer,'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Choose an address owned by this customer.');
        }
    }
    public function insertAddress(int $customer, array $values): void
    {
        $statement = $this->db->prepare('INSERT INTO customer_addresses(customer_id,address_label,street_address,delegation,governorate,postal_code) VALUES(:customer,:address_label,:street_address,:delegation,:governorate,:postal_code)');
        $statement->execute($values + ['customer' => $customer]);
    }
    public function deleteAddress(int $customer, int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM customer_addresses WHERE customer_id=:customer AND id=:id');
        $statement->execute(['customer' => $customer,'id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Choose an address owned by this customer.');
        }
    }
    public function ensurePrimaryAddress(int $customer): void
    {
        $statement = $this->db->prepare('UPDATE customer_addresses SET is_primary=CASE WHEN id=(SELECT id FROM customer_addresses WHERE customer_id=:owner ORDER BY is_primary DESC,id LIMIT 1) THEN 1 ELSE 0 END WHERE customer_id=:customer');
        $statement->execute(['owner' => $customer,'customer' => $customer]);
    }

    /** The profile service holds the owning account lock for this transaction. */
    public function setPrimaryAddress(int $customer, int $address): void
    {
        $statement = $this->db->prepare('SELECT 1 FROM customer_addresses WHERE customer_id=:customer AND id=:address');
        $statement->execute(['customer' => $customer, 'address' => $address]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Choose an address owned by this customer.');
        }
        // Clear first so changing the default also works with a unique-primary index.
        $this->db->prepare('UPDATE customer_addresses SET is_primary=0 WHERE customer_id=:customer')->execute(['customer' => $customer]);
        $this->db->prepare('UPDATE customer_addresses SET is_primary=1 WHERE customer_id=:customer AND id=:address')
            ->execute(['customer' => $customer, 'address' => $address]);
    }
    public function archiveRequest(int $id, int $actor, bool $archived): void
    {
        $statement = $this->db->prepare('UPDATE custom_product_requests SET archived_at=' . ($archived ? 'CURRENT_TIMESTAMP' : 'NULL') . ', archived_by_administrator_id=:actor WHERE id=:id');
        $statement->execute(['actor' => $archived ? $actor : null,'id' => $id]);
    }
}
