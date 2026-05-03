<?php

declare(strict_types=1);

namespace Pubvana\Themes\Models;

/**
 * Theme ActiveRecord model.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $folder
 * @property string|null $description
 * @property string|null $version
 * @property string|null $author
 * @property int         $is_active       0|1
 * @property int|null    $disabled        null = not disabled, 1 = disabled
 * @property string|null $disabled_reason
 * @property string|null $screenshot      Relative path within theme assets
 * @property string      $installed_at
 * @property string      $created_at
 * @property string      $updated_at
 */
class Theme extends \flight\ActiveRecord
{
    public function __construct($pdo = null, array $config = [])
    {
        parent::__construct($pdo, 'themes', $config);
    }

    /**
     * Find the currently active theme.
     */
    public function findActive(): ?self
    {
        $query = new self($this->getDatabaseConnection());
        $query->eq('is_active', 1)
              ->eq('disabled', 0)
              ->find();

        return $query->isHydrated() ? $query : null;
    }

    /**
     * Find a theme by its folder name.
     */
    public function findByFolder(string $folder): ?self
    {
        $query = new self($this->getDatabaseConnection());
        $query->eq('folder', $folder)->find();

        return $query->isHydrated() ? $query : null;
    }

    /**
     * Get all themes.
     *
     * @return self[]
     */
    public function getAll(): array
    {
        $query = new self($this->getDatabaseConnection());
        return $query->order('name ASC')->findAll();
    }

    /**
     * Deactivate all themes, then activate the given one.
     */
    public function activateById(int $id): void
    {
        $pdo = $this->getDatabaseConnection();

        // Deactivate all other themes
        $others = (new self($pdo))->notEq('id', $id)->findAll();
        foreach ($others as $other) {
            $other->is_active = 0;
            $other->save();
        }

        // Activate the chosen one
        $theme = new self($pdo);
        $theme->eq('id', $id)->find();
        if ($theme->isHydrated()) {
            $theme->is_active = 1;
            $theme->save();
        }
    }

    /**
     * Deactivate a theme and fall back to default.
     */
    public function deactivateAndFallback(int $id): void
    {
        $pdo = $this->getDatabaseConnection();

        $theme = new self($pdo);
        $theme->eq('id', $id)->find();
        if ($theme->isHydrated()) {
            $theme->is_active = 0;
            $theme->save();
        }

        $default = new self($pdo);
        $default->eq('folder', 'default')->find();
        if ($default->isHydrated()) {
            $default->is_active = 1;
            $default->save();
        }
    }
}
