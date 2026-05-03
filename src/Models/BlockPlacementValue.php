<?php

declare(strict_types=1);

namespace Pubvana\Themes\Models;

/**
 * BlockPlacementValue ActiveRecord model.
 *
 * Per-placement field values using dot-notation keys.
 * Repeater fields use compound keys: links.0.label, links.0.url, links.1.label, etc.
 *
 * @property int         $id
 * @property int         $placement_id
 * @property string      $field_key
 * @property string|null $field_value
 * @property int         $sort_order
 */
class BlockPlacementValue extends \flight\ActiveRecord
{
    public function __construct($pdo = null, array $config = [])
    {
        parent::__construct($pdo, 'block_placement_values', $config);
    }

    /**
     * Get all values for a placement as a flat key => value array.
     *
     * @return array<string, string|null>
     */
    public function getForPlacement(int $placementId): array
    {
        $rows = (new self($this->getDatabaseConnection()))
            ->eq('placement_id', $placementId)
            ->order('sort_order ASC')
            ->findAll();

        $values = [];
        foreach ($rows as $row) {
            $values[$row->field_key] = $row->field_value;
        }

        return $values;
    }

    /**
     * Get values for a placement expanded into nested arrays.
     *
     * Dot-notation keys become nested structure:
     *   'title' => 'Quick Links'
     *   'links.0.label' => 'Home'
     *   'links.0.url' => '/'
     * becomes:
     *   ['title' => 'Quick Links', 'links' => [['label' => 'Home', 'url' => '/']]]
     *
     * @return array<string, mixed>
     */
    public function getNestedForPlacement(int $placementId): array
    {
        $flat = $this->getForPlacement($placementId);
        $nested = [];

        foreach ($flat as $key => $value) {
            $parts = explode('.', $key);

            if (count($parts) === 1) {
                $nested[$key] = $value;
            } elseif (count($parts) === 3 && is_numeric($parts[1])) {
                // Repeater: field.index.subfield
                $nested[$parts[0]][(int) $parts[1]][$parts[2]] = $value;
            } else {
                // Two-level dot notation: group.field
                $nested[$parts[0]][$parts[1]] = $value;
            }
        }

        // Re-index repeater arrays to remove gaps
        foreach ($nested as $key => &$value) {
            if (is_array($value) && !empty($value) && array_is_list($value)) {
                $value = array_values($value);
            }
        }

        return $nested;
    }

    /**
     * Save a single value for a placement.
     */
    public function saveValue(int $placementId, string $key, ?string $value, int $sortOrder = 0): void
    {
        $existing = (new self($this->getDatabaseConnection()))
            ->eq('placement_id', $placementId)
            ->eq('field_key', $key)
            ->find();

        if ($existing->isHydrated()) {
            $existing->field_value = $value;
            $existing->sort_order = $sortOrder;
            $existing->save();
        } else {
            $new = new self($this->getDatabaseConnection());
            $new->placement_id = $placementId;
            $new->field_key = $key;
            $new->field_value = $value;
            $new->sort_order = $sortOrder;
            $new->insert();
        }
    }

    /**
     * Delete all values for a placement.
     */
    public function deleteForPlacement(int $placementId): void
    {
        $rows = (new self($this->getDatabaseConnection()))
            ->eq('placement_id', $placementId)
            ->findAll();

        foreach ($rows as $row) {
            $row->delete();
        }
    }
}
