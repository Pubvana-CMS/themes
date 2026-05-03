<?php

declare(strict_types=1);

namespace Pubvana\Themes\Models;

/**
 * BlockPlacement ActiveRecord model.
 *
 * Maps registered blocks to regions with ordering.
 *
 * @property int         $id
 * @property string      $region_id   Region key (e.g. 'sidebar', 'header')
 * @property string      $block_key   Adext block key (e.g. 'pubvana.blog.recent-posts')
 * @property int         $sort_order
 * @property string|null $created_at
 */
class BlockPlacement extends \flight\ActiveRecord
{
    public function __construct($pdo = null, array $config = [])
    {
        parent::__construct($pdo, 'block_placements', $config);
    }

    /**
     * Get all placements for a region, ordered by sort_order.
     *
     * @return self[]
     */
    public function getForRegion(string $regionId): array
    {
        $query = new self($this->getDatabaseConnection());
        return $query->eq('region_id', $regionId)
                     ->order('sort_order ASC')
                     ->findAll();
    }

    /**
     * Get all placements.
     *
     * @return self[]
     */
    public function getAll(): array
    {
        $query = new self($this->getDatabaseConnection());
        return $query->order('region_id ASC, sort_order ASC')->findAll();
    }

    /**
     * Find a specific placement by region + block key.
     */
    public function findPlacement(string $regionId, string $blockKey): ?self
    {
        $query = new self($this->getDatabaseConnection());
        $query->eq('region_id', $regionId)
              ->eq('block_key', $blockKey)
              ->find();

        return $query->isHydrated() ? $query : null;
    }

    /**
     * Get the next sort_order value for a region.
     */
    public function nextSortOrder(string $regionId): int
    {
        $existing = $this->getForRegion($regionId);
        if (empty($existing)) {
            return 0;
        }

        $max = 0;
        foreach ($existing as $row) {
            if ($row->sort_order > $max) {
                $max = $row->sort_order;
            }
        }

        return $max + 1;
    }
}
