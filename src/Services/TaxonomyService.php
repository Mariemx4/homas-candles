<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Taxonomy Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\TaxonomyRepository;
use DomainException;

final class TaxonomyService
{
    public const KINDS = TaxonomyRepository::KINDS;

    private const LABELS = [
        'categories' => ['singular' => 'Category', 'plural' => 'Categories'],
        'product_types' => ['singular' => 'Product type', 'plural' => 'Product Types'],
        'colors' => ['singular' => 'Color', 'plural' => 'Colors'],
        'scents' => ['singular' => 'Scent', 'plural' => 'Scents'],
    ];

    private const SLUG_MAX_LENGTH = 150;
    public const PER_PAGE = 20;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly TaxonomyRepository $taxonomy,
        private readonly ProductReadinessService $readiness
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `label` (label) and return its result.
     */
    public static function label(string $kind, bool $plural = false): string
    {
        return self::LABELS[$kind][$plural ? 'plural' : 'singular'] ?? ucfirst($kind);
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @return array<string, mixed>
     */
    public function list(string $kind, array $query): array
    {
        $normalized = $this->normalizeListQuery($query);
        $filters = $normalized['filters'];
        $total = $this->taxonomy->countForList($kind, $filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->taxonomy->findForList(
            $kind,
            $filters,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        $state = $this->queryState($normalized, $page);

        return [
            'items' => $rows,
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>
     */
    public function detail(string $kind, ?int $id): array
    {
        if ($id === null) {
            $extras = [];
            if ($kind === 'colors') {
                $extras['hex_code'] = '';
            }
            if ($kind === 'scents') {
                $extras['fragrance_material_id'] = null;
            }

            return [
                'id' => null,
                'slug' => '',
                'is_active' => true,
                'translations' => ['en' => '', 'fr' => '', 'ar' => ''],
            ] + $extras;
        }

        $entity = $this->taxonomy->find($kind, $id);
        if ($entity === null) {
            return [];
        }
        $entity['is_active'] = (bool) $entity['is_active'];

        return $entity;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `fragranceMaterialOptions` (fragrance material options) and return its result.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function fragranceMaterialOptions(): array
    {
        return $this->taxonomy->findActiveFragranceMaterialOptions();
    }

    /**
     * FUNCTION PURPOSE: Validate and persist the supplied value.
     *
     * @return array{success: bool, errors: array<string, string>, id?: int}
     */
    public function save(string $kind, ?int $id, array $input): array
    {
        [$core, $translations, $errors] = $this->validate($kind, $id, $input);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // WHAT: the save and the readiness re-check share one transaction.
            // WHY:  the edit form can also switch is_active off; a retired color or
            //       scent can leave active products without a sellable option, and
            //       those return to draft together with this save.
            $applied = $this->readiness->applyChange(function () use ($kind, $id, $core, $translations): int {
                $savedId = $id;
                if ($id === null) {
                    $savedId = $this->taxonomy->insert($kind, $core);
                } else {
                    $this->taxonomy->update($kind, $id, $core);
                }
                foreach ($translations as $languageCode => $name) {
                    $this->taxonomy->upsertTranslation($kind, $savedId, $languageCode, $name === '' ? null : $name);
                }

                return $savedId;
            });
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return ['success' => false, 'errors' => ['slug' => 'This slug is already used by another ' . strtolower(self::label($kind)) . '.']];
            }

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'id' => $applied['result'], 'notice' => $applied['notice']];
    }

    /**
     * FUNCTION PURPOSE: Switch active to the requested boolean state after validation.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function toggleActive(string $kind, int $id, bool $active): array
    {
        if ($this->taxonomy->find($kind, $id) === null) {
            return ['success' => false, 'errors' => ['form' => strtolower(self::label($kind)) . ' could not be found.']];
        }
        // Deactivating a color or scent returns dependent products to draft atomically.
        $applied = $this->readiness->applyChange(fn () => $this->taxonomy->setActive($kind, $id, $active));

        return ['success' => true, 'errors' => [], 'notice' => $applied['notice']];
    }

    /**
     * FUNCTION PURPOSE: Check the supplied value against the application rules and return usable values or errors.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    private function validate(string $kind, ?int $id, array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        $slug = strtolower($string('slug'));
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if ($slug === '' || strlen($slug) > self::SLUG_MAX_LENGTH || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $errors['slug'] = 'Enter a slug using lowercase letters, numbers, and single hyphens only.';
        } elseif ($this->taxonomy->slugExists($kind, $slug, $id)) {
            $errors['slug'] = 'This slug is already used by another ' . strtolower(self::label($kind)) . '.';
        }

        $isActive = ($input['is_active'] ?? '') === '1';

        $translations = [];
        foreach (['en', 'fr', 'ar'] as $languageCode) {
            $name = $string('name_' . $languageCode);
            if (strlen($name) > 150) {
                $errors['name_' . $languageCode] = 'The name must be 150 characters or fewer.';
                $name = substr($name, 0, 150);
            }
            $translations[$languageCode] = $name;
        }
        if ($translations['en'] === '') {
            $errors['name_en'] = 'An English name is required.';
        }

        $core = ['slug' => $slug, 'is_active' => $isActive ? 1 : 0];

        if ($kind === 'colors') {
            $hexCode = $string('hex_code');
            // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
            if ($hexCode !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $hexCode) !== 1) {
                $errors['hex_code'] = 'Enter a hex color like #F4DADA, or leave it blank.';
                $hexCode = '';
            }
            $core['hex_code'] = $hexCode === '' ? null : $hexCode;
        }

        if ($kind === 'scents') {
            $rawMaterialId = $string('fragrance_material_id');
            $materialId = null;
            if ($rawMaterialId !== '') {
                // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
                $materialId = filter_var($rawMaterialId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($materialId === false) {
                    $errors['fragrance_material_id'] = 'Select a valid material, or leave it unlinked.';
                    $materialId = null;
                } elseif (!$this->taxonomy->activeMaterialExists($materialId)) {
                    $errors['fragrance_material_id'] = 'Select an active material, or leave it unlinked.';
                    $materialId = null;
                } elseif ($this->taxonomy->fragranceMaterialLinkedToOtherScent($materialId, $id)) {
                    $errors['fragrance_material_id'] = 'This material is already linked to another scent.';
                    $materialId = null;
                }
            }
            $core['fragrance_material_id'] = $materialId;
        }

        return [$core, $translations, $errors];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert list query into a consistent internal shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = trim($this->queryString($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $active = $this->queryString($query, 'active', $adjusted);
        if (!in_array($active, ['', '0', '1'], true)) {
            $active = '';
            $adjusted = true;
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'active' => $active === '' ? null : $active,
            ],
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the state query value.
     *
     * @return array<string, mixed>
     */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'q' => $filters['search'],
            'active' => $filters['active'],
            'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }
}
