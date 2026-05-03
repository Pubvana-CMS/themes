<?php

declare(strict_types=1);

namespace Pubvana\Themes\Services;

use Enlivenapp\FlightSchool\Exception\ValidationException;
use Pubvana\Themes\Models\Theme;
use Pubvana\Themes\Models\ThemeOption;
use flight\database\PdoWrapper;

/**
 * Service layer for theme discovery, sync, activation, validation, and options.
 */
class ThemeService
{
    protected PdoWrapper $pdo;
    protected ?object $activeTheme = null;

    /** @var array<string, bool> folder => isValid (populated after sync) */
    protected array $validationResults = [];

    public function __construct(PdoWrapper $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Scan the themes directory and return manifest data for each theme found.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discover(): array
    {
        $themesPath = $this->getThemesPath();
        $themes = [];

        foreach (glob($themesPath . '*', GLOB_ONLYDIR) as $dir) {
            $folder = basename($dir);
            $jsonFile = $dir . '/pubvana.json';

            if (!is_file($jsonFile)) {
                continue;
            }

            $info = json_decode(file_get_contents($jsonFile), true);
            $disabledReason = null;

            if (!is_array($info)) {
                $disabledReason = "Invalid pubvana.json in theme '{$folder}'.";
                $info = [];
            }

            $info['folder'] = $folder;
            $info['_disabled_reason'] = $disabledReason;
            $themes[] = $info;
        }

        return $themes;
    }

    /**
     * Reconcile filesystem themes with the database.
     *
     * Inserts new themes, updates changed ones, removes orphaned records.
     */
    public function sync(): void
    {
        $model = $this->themeModel();
        $now = date('Y-m-d H:i:s');

        // Remove orphaned DB records (theme folder deleted from disk)
        $registered = $model->getAll();
        foreach ($registered as $row) {
            if (!is_dir($this->getThemesPath() . $row->folder)) {
                $row->delete();
            }
        }

        foreach ($this->discover() as $info) {
            $folder = $info['folder'];
            $disabledReason = $info['_disabled_reason'];
            $existing = $this->themeModel()->findByFolder($folder);

            $name = $info['display_name'] ?? $folder;
            $description = $info['description'] ?? '';
            $version = $info['version'] ?? null;
            $author = $info['author'] ?? null;
            $screenshot = $info['screenshot'] ?? null;

            if ($disabledReason !== null) {
                if ($existing) {
                    $existing->is_active = 0;
                    $existing->disabled = 1;
                    $existing->disabled_reason = $disabledReason;
                    $existing->save();
                } else {
                    $new = $this->themeModel();
                    $new->name = $name;
                    $new->folder = $folder;
                    $new->description = $description;
                    $new->version = $version;
                    $new->author = $author;
                    $new->screenshot = $screenshot;
                    $new->is_active = 0;
                    $new->disabled = 1;
                    $new->disabled_reason = $disabledReason;
                    $new->installed_at = $now;
                    $new->created_at = $now;
                    $new->updated_at = $now;
                    $new->insert();
                }

                $this->validationResults[$folder] = $this->validateTheme($folder);
                $this->publishAssets($folder);
                continue;
            }

            // Valid theme
            if (!$existing) {
                $new = $this->themeModel();
                $new->name = $name;
                $new->folder = $folder;
                $new->description = $description;
                $new->version = $version;
                $new->author = $author;
                $new->screenshot = $screenshot;
                $new->is_active = 0;
                $new->installed_at = $now;
                $new->created_at = $now;
                $new->updated_at = $now;
                $new->insert();
            } else {
                $changed = false;

                if (($existing->name ?? '') !== $name) {
                    $existing->name = $name;
                    $changed = true;
                }
                if (($existing->description ?? '') !== $description) {
                    $existing->description = $description;
                    $changed = true;
                }
                if (($existing->version ?? '') !== ($version ?? '')) {
                    $existing->version = $version;
                    $changed = true;
                }
                if (($existing->author ?? '') !== ($author ?? '')) {
                    $existing->author = $author;
                    $changed = true;
                }
                if (($existing->screenshot ?? '') !== ($screenshot ?? '')) {
                    $existing->screenshot = $screenshot;
                    $changed = true;
                }

                // Clear disabled state if previously disabled
                if (!empty($existing->disabled)) {
                    $existing->disabled = 0;
                    $existing->disabled_reason = null;
                    $changed = true;
                }

                if ($changed) {
                    $existing->updated_at = $now;
                    $existing->save();
                }
            }

            $this->validationResults[$folder] = $this->validateTheme($folder);
            $this->publishAssets($folder);

            // Seed default options from pubvana.json
            $theme = $this->themeModel()->findByFolder($folder);
            if ($theme) {
                $this->syncDefaultOptions($theme, $info);
            }
        }

        // If no theme is active, activate default
        if ($this->themeModel()->findActive() === null) {
            $default = $this->themeModel()->findByFolder('default');
            if ($default) {
                $default->is_active = 1;
                $default->save();
            }
        }
    }

    /**
     * Get the currently active theme.
     */
    public function getActive(): ?object
    {
        if ($this->activeTheme !== null) {
            return $this->activeTheme;
        }

        $this->activeTheme = $this->themeModel()->findActive();
        return $this->activeTheme;
    }

    /**
     * Activate a theme by ID.
     *
     * @return string Status: 'activated', 'not_found', 'disabled', 'invalid'
     */
    public function activate(int $id): string
    {
        $theme = $this->themeModel();
        $theme->eq('id', $id)->find();

        if (!$theme->isHydrated()) {
            return 'not_found';
        }

        if (!empty($theme->disabled)) {
            return 'disabled';
        }

        if (!$this->validateTheme($theme->folder)) {
            return 'invalid';
        }

        $this->themeModel()->activateById($id);
        $this->activeTheme = null;
        $this->publishAssets($theme->folder);

        return 'activated';
    }

    /**
     * Scan theme files for PHP tags.
     *
     * Returns true if the theme is clean (no PHP), false if PHP is found.
     */
    public function validateTheme(string $folder): bool
    {
        $dir = $this->getThemesPath() . $folder;
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $skipExtensions = [
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico',
            'woff', 'woff2', 'ttf', 'eot', 'otf', 'zip',
        ];

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (in_array($ext, $skipExtensions, true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if (str_contains($content, '<?php') || str_contains($content, '<?=') || str_contains($content, '<%')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy theme assets to the web-accessible directory.
     *
     * themes/{folder}/assets/* -> public/themes/{folder}/
     */
    public function publishAssets(string $folder): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folder)) {
            throw new ValidationException('Invalid theme folder name: ' . $folder);
        }

        $source = $this->getThemesPath() . $folder . '/assets';
        $dest = $this->getPublicPath() . 'themes/' . $folder . '/assets';

        if (!is_dir($source)) {
            return;
        }

        if (is_dir($dest)) {
            $this->removeDirectory($dest);
        }

        if (is_link($dest)) {
            unlink($dest);
        }

        $parentDir = $this->getPublicPath() . 'themes/' . $folder;
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $this->copyDirectory($source, $dest);
    }

    /**
     * Get validation results (populated after sync() runs).
     *
     * @return array<string, bool> folder => isValid
     */
    public function getValidationResults(): array
    {
        return $this->validationResults;
    }

    /**
     * Get a theme option value.
     */
    public function getThemeOption(int $themeId, string $key, ?string $default = null): ?string
    {
        return $this->optionModel()->getOption($themeId, $key, $default);
    }

    /**
     * Save a theme option value.
     */
    public function saveThemeOption(int $themeId, string $key, string $value): void
    {
        $this->optionModel()->saveOption($themeId, $key, $value);
    }

    /**
     * Get all options for a theme as key => value.
     *
     * @return array<string, string|null>
     */
    public function getThemeOptions(int $themeId): array
    {
        $rows = $this->optionModel()->getForTheme($themeId);
        $options = [];
        foreach ($rows as $row) {
            $options[$row->option_key] = $row->option_value;
        }
        return $options;
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /**
     * Seed default theme options from pubvana.json if they don't already exist.
     */
    protected function syncDefaultOptions(object $theme, array $info): void
    {
        $options = $info['provides']['options'] ?? [];
        if (empty($options)) {
            return;
        }

        foreach ($options as $key => $def) {
            if (($def['type'] ?? '') === 'group') {
                foreach (($def['fields'] ?? []) as $fKey => $fDef) {
                    $this->optionModel()->seedDefault(
                        (int) $theme->id,
                        $key . '.' . $fKey,
                        $fDef['default'] ?? ''
                    );
                }
            } else {
                $this->optionModel()->seedDefault(
                    (int) $theme->id,
                    $key,
                    $def['default'] ?? ''
                );
            }
        }
    }

    protected function getThemesPath(): string
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 5);
        return rtrim($root, '/') . '/themes/';
    }

    protected function getPublicPath(): string
    {
        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 5);
        return rtrim($root, '/') . '/public/';
    }

    private function copyDirectory(string $source, string $dest): void
    {
        mkdir($dest, 0755, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function themeModel(): Theme
    {
        return new Theme($this->pdo);
    }

    private function optionModel(): ThemeOption
    {
        return new ThemeOption($this->pdo);
    }
}
