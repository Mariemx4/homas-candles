<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use DomainException;

/**
 * Replaces product photos (the main image and per-color images) safely.
 *
 * Owner: catalogue administration (reached through AdminProductService).
 * File validation and storage belong to ProductImageStorage; this class owns
 * the ORDER of file and database changes, which is the part that is easy to
 * get wrong:
 *
 * 1. store the new file;
 * 2. point the database row at it inside a transaction;
 * 3. if the transaction fails, delete the new file;
 * 4. after commit, delete the previous file, but only when no other live
 *    record still references that path (sets and colors can share files).
 *
 * This order never leaves the database pointing at a missing file.
 */
final class ProductImageService
{
    public function __construct(
        private readonly AdminProductRepository $products,
        private readonly TransactionManager $transactions,
        private readonly ProductImageStorage $images
    ) {
    }

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, errors: array<string, string>}
     */
    public function replaceMainImage(int $productId, ?array $file): array
    {
        return $this->replace($file, 'main_image', function (string $publicPath) use ($productId): ?string {
            $product = $this->products->lockCore($productId);
            if ($product === null) {
                throw new DomainException('The product could not be found.');
            }
            $this->products->setMainImage($productId, $publicPath);

            return $product['main_image_path'] === null ? null : (string) $product['main_image_path'];
        });
    }

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, errors: array<string, string>}
     */
    public function replaceColorImage(int $productId, int $colorId, ?array $file): array
    {
        return $this->replace($file, 'color_image', function (string $publicPath) use ($productId, $colorId): ?string {
            if (!$this->products->colorAssigned($productId, $colorId)) {
                throw new DomainException('Assign this color to the product before uploading its photo.');
            }
            $previous = $this->products->findColorImagePath($productId, $colorId);
            $this->products->setColorImage($productId, $colorId, $publicPath);

            return $previous;
        });
    }

    /** Delete a file that a committed change no longer references, if nothing else uses it. */
    public function discardIfUnused(?string $publicPath): void
    {
        if ($publicPath !== null && !$this->products->imagePathInUse($publicPath)) {
            $this->images->discard($publicPath);
        }
    }

    /**
     * Shared store → update → clean-up sequence.
     *
     * @param string $field Form field that receives upload validation errors.
     * @param callable(string): ?string $pointRecordAt Runs inside the transaction;
     *        points the record at the new public path and returns the previous path.
     */
    private function replace(?array $file, string $field, callable $pointRecordAt): array
    {
        $stored = $this->images->store($file);
        if (!$stored['success']) {
            return ['success' => false, 'errors' => [$field => (string) $stored['error']]];
        }
        if ($stored['path'] === null) {
            return ['success' => false, 'errors' => [$field => 'Choose an image file to upload.']];
        }
        // The database stores a browser-usable relative path, not a server path.
        $publicPath = $this->images->publicRelativePath($stored['path']);

        try {
            $previousPath = $this->transactions->run(static fn (): ?string => $pointRecordAt($publicPath));
        } catch (DomainException $exception) {
            $this->images->discard($stored['path']);

            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\Throwable $exception) {
            $this->images->discard($stored['path']);

            throw $exception;
        }

        $this->discardIfUnused($previousPath);

        return ['success' => true, 'errors' => []];
    }
}
