<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class CartService
{
    private const SESSION_KEY = 'cart_lines';
    private const MAX_LINES = 50;
    public const MAX_QUANTITY = 1000;

    public function __construct(private readonly ProductService $products)
    {
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $stored = $this->storedLines();
        $valid = [];
        $items = [];
        $accessoryQuantities = [];

        foreach ($stored as $key => $line) {
            if (!is_array($line) || !is_string($line['slug'] ?? null)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $line['slug']) !== 1
            ) {
                continue;
            }
            $data = $this->products->findForCatalogue($line['slug']);
            if ($data === null || !$this->isCartProduct($data['product'])) {
                continue;
            }
            $product = $data['product'];
            try {
                $quantity = $this->quantity($line['quantity'] ?? null, (int) $product['minimum_quantity']);
                [$colorId, $colorName, $colorImage] = $this->option(
                    $line['color_id'] ?? null,
                    $data['colors'],
                    'colour',
                    true
                );
                [$scentId, $scentName] = $this->option(
                    $line['scent_id'] ?? null,
                    $data['scents'],
                    'scent',
                    false
                );
                $this->requiredOptions($product, $colorId, $scentId);
            } catch (DomainException) {
                continue;
            }
            $lineId = $this->lineId($product['slug'], $colorId, $scentId);
            if ($key !== $lineId || isset($valid[$lineId])) {
                continue;
            }
            if ($this->isAccessory($product)) {
                $accessoryQuantities[$product['slug']] = ($accessoryQuantities[$product['slug']] ?? 0) + $quantity;
                if ($accessoryQuantities[$product['slug']] > (int) $product['stock_quantity']) {
                    $accessoryQuantities[$product['slug']] -= $quantity;
                    continue;
                }
            }
            $valid[$lineId] = [
                'slug' => $product['slug'], 'color_id' => $colorId,
                'scent_id' => $scentId, 'quantity' => $quantity,
            ];
            $items[$lineId] = [
                'line_id' => $lineId,
                // IDs let checkout link each order item back to the live
                // catalogue rows while it freezes the names/prices below.
                'product_id' => (int) $product['id'],
                'color_id' => $colorId,
                'scent_id' => $scentId,
                'slug' => $product['slug'],
                'name' => $product['name'],
                'category' => $product['category_name'],
                'category_slug' => $product['category_slug'],
                'image' => $colorImage ?? $product['main_image_path'],
                'color' => $colorName,
                'scent' => $scentName,
                'price' => (float) $product['selling_price'],
                'quantity' => $quantity,
                'minimum_quantity' => (int) $product['minimum_quantity'],
                'max_quantity' => $this->isAccessory($product)
                    ? min(self::MAX_QUANTITY, (int) $product['stock_quantity'])
                    : self::MAX_QUANTITY,
            ];
        }

        if (!$this->hasAnchor($items)) {
            foreach ($items as $key => $item) {
                if ($item['category_slug'] === CatalogueCategory::ACCESSORIES) {
                    unset($items[$key], $valid[$key]);
                }
            }
        }
        $_SESSION[self::SESSION_KEY] = $valid;

        return array_values($items);
    }


    /** Empties the cart (after an order is placed). */
    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Whether the cart holds a candle or candle set, which accessories require. */
    public function hasQualifyingProduct(): bool
    {
        return $this->hasAnchor($this->items());
    }

    /**
     * Adds a product with its chosen colour, scent and quantity to the session cart.
     *
     * Accessories can only be added next to a candle or candle set.
     *
     * @throws DomainException with a message for the customer
     */
    public function add(array $input): void
    {
        $items = $this->items();
        $slug = $input['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new DomainException('Choose an available product.');
        }
        $data = $this->products->findForCatalogue($slug);
        if ($data === null || !$this->isCartProduct($data['product'])) {
            throw new DomainException('This product is not available for the cart.');
        }
        $product = $data['product'];
        if ($this->isAccessory($product) && !$this->hasAnchor($items)) {
            throw new DomainException('Add a candle or candle set before adding an accessory.');
        }
        $quantity = $this->quantity($input['quantity'] ?? null, (int) $product['minimum_quantity']);
        [$colorId] = $this->option($input['color'] ?? null, $data['colors'], 'colour', true);
        [$scentId] = $this->option($input['scent'] ?? null, $data['scents'], 'scent', false, true);
        $this->requiredOptions($product, $colorId, $scentId);

        $lineId = $this->lineId($slug, $colorId, $scentId);
        $stored = $this->storedLines();
        $existing = $stored[$lineId]['quantity'] ?? 0;
        $combined = $quantity + (is_int($existing) ? $existing : 0);
        $this->quantity($combined, (int) $product['minimum_quantity']);
        if (!isset($stored[$lineId]) && count($stored) >= self::MAX_LINES) {
            throw new DomainException('The cart has reached its item limit.');
        }
        $this->assertStock($product, $items, $lineId, $combined);
        $stored[$lineId] = [
            'slug' => $slug, 'color_id' => $colorId,
            'scent_id' => $scentId, 'quantity' => $combined,
        ];
        $_SESSION[self::SESSION_KEY] = $stored;
    }

    /**
     * Changes the quantity of one cart line.
     *
     * @throws DomainException with a message for the customer
     */
    public function update(array $input): void
    {
        $items = $this->items();
        $lineId = $input['line_id'] ?? null;
        if (!is_string($lineId) || !isset($this->storedLines()[$lineId])) {
            throw new DomainException('This cart item is no longer available.');
        }
        foreach ($items as $item) {
            if ($item['line_id'] !== $lineId) {
                continue;
            }
            $quantity = $this->quantity($input['quantity'] ?? null, $item['minimum_quantity']);
            if ($item['category_slug'] === CatalogueCategory::ACCESSORIES) {
                $total = $quantity;
                foreach ($items as $other) {
                    if ($other['slug'] === $item['slug'] && $other['line_id'] !== $lineId) {
                        $total += $other['quantity'];
                    }
                }
                if ($total > $item['max_quantity']) {
                    throw new DomainException('The requested accessory quantity exceeds available stock.');
                }
            }
            $stored = $this->storedLines();
            $stored[$lineId]['quantity'] = $quantity;
            $_SESSION[self::SESSION_KEY] = $stored;
            return;
        }
        throw new DomainException('This cart item is no longer available.');
    }

    /**
     * Removes one cart line; the last candle or set cannot be removed while an accessory remains.
     *
     * @throws DomainException with a message for the customer
     */
    public function remove(array $input): void
    {
        $items = $this->items();
        $lineId = $input['line_id'] ?? null;
        if (!is_string($lineId) || !isset($this->storedLines()[$lineId])) {
            throw new DomainException('This cart item is no longer available.');
        }
        $remaining = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['line_id'] !== $lineId
        ));
        if (!$this->hasAnchor($remaining)) {
            foreach ($remaining as $item) {
                if ($item['category_slug'] === CatalogueCategory::ACCESSORIES) {
                    throw new DomainException('Remove the accessory before the last candle or set.');
                }
            }
        }
        $stored = $this->storedLines();
        unset($stored[$lineId]);
        $_SESSION[self::SESSION_KEY] = $stored;
    }

    private function isCartProduct(array $product): bool
    {
        return $product['purchase_mode'] === 'CART'
            && !in_array($product['category_slug'], [CatalogueCategory::BOUQUETS, CatalogueCategory::EVENT_FAVORS], true)
            && $product['selling_price'] !== null
            && is_numeric($product['selling_price'])
            && (float) $product['selling_price'] >= 0;
    }

    private function isAccessory(array $product): bool
    {
        return $product['category_slug'] === CatalogueCategory::ACCESSORIES;
    }

    private function hasAnchor(array $items): bool
    {
        foreach ($items as $item) {
            if (in_array($item['category_slug'], [CatalogueCategory::CANDLES, CatalogueCategory::SETS], true)) {
                return true;
            }
        }
        return false;
    }

    private function quantity(mixed $raw, int $minimum): int
    {
        if (!is_int($raw) && (!is_string($raw) || !ctype_digit($raw))) {
            throw new DomainException('Enter a valid whole-number quantity.');
        }
        $quantity = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => max(1, $minimum), 'max_range' => self::MAX_QUANTITY],
        ]);
        if ($quantity === false) {
            throw new DomainException('The quantity is outside the allowed range.');
        }
        return $quantity;
    }


    private function option(mixed $raw, array $options, string $label, bool $requiresImage, bool $requiresAvailability = false): array
    {
        if ($options === []) {
            if ($raw !== null && $raw !== '') {
                throw new DomainException('This product has no ' . $label . ' options.');
            }
            return [null, Locale::text('cart.not_applicable'), null];
        }
        if (!is_string($raw) && !is_int($raw)) {
            throw new DomainException('Choose an available ' . $label . '.');
        }
        $id = (string) $raw;
        if (!ctype_digit($id) || (int) $id < 1) {
            throw new DomainException('Choose an available ' . $label . '.');
        }
        foreach ($options as $option) {
            if ((int) $option['id'] === (int) $id) {
                if ($requiresImage && ($option['image_path'] ?? null) === null) {
                    throw new DomainException('This ' . $label . ' is currently unavailable.');
                }
                if ($requiresAvailability && ($option['is_available'] ?? true) === false) {
                    throw new DomainException('This ' . $label . ' is out of stock for now. Please choose another one.');
                }
                return [(int) $id, (string) $option['name'], $option['image_path'] ?? null];
            }
        }
        throw new DomainException('Choose an available ' . $label . '.');
    }

    private function requiredOptions(array $product, ?int $colorId, ?int $scentId): void
    {
        $category = $product['category_slug'];
        if (in_array($category, [CatalogueCategory::CANDLES, CatalogueCategory::SETS, CatalogueCategory::DIFFUSERS, CatalogueCategory::ACCESSORIES], true)
            && $colorId === null
        ) {
            throw new DomainException('Choose an available colour.');
        }
        if (in_array($category, [CatalogueCategory::CANDLES, CatalogueCategory::SETS, CatalogueCategory::DIFFUSERS], true)
            && $scentId === null
        ) {
            throw new DomainException('Choose an available scent.');
        }
        if ($category === CatalogueCategory::ACCESSORIES && $scentId !== null) {
            throw new DomainException('Accessories do not have a scent option.');
        }
    }

    private function assertStock(array $product, array $items, string $lineId, int $quantity): void
    {
        if (!$this->isAccessory($product)) {
            return;
        }
        $total = $quantity;
        foreach ($items as $item) {
            if ($item['slug'] === $product['slug'] && $item['line_id'] !== $lineId) {
                $total += $item['quantity'];
            }
        }
        if ($total > (int) $product['stock_quantity']) {
            throw new DomainException('The requested accessory quantity exceeds available stock.');
        }
    }

    private function lineId(string $slug, ?int $colorId, ?int $scentId): string
    {
        return hash('sha256', $slug . '|' . ($colorId ?? '') . '|' . ($scentId ?? ''));
    }

    private function storedLines(): array
    {
        $lines = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($lines) ? $lines : [];
    }
}
