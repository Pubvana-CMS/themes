<?php

declare(strict_types=1);

namespace Pubvana\Themes\Services;

use Enlivenapp\FlightSchool\PluginView;
use Pubvana\Themes\Models\BlockPlacement;
use Pubvana\Themes\Models\BlockPlacementValue;
use flight\database\PdoWrapper;
use flight\Engine;

/**
 * Manages theme regions and block placements — placement CRUD, rendering, and region discovery.
 */
class RegionManager
{
    protected Engine $app;
    protected PdoWrapper $pdo;

    /** Platform regions — always available regardless of theme. */
    protected const PLATFORM_REGIONS = [
        'header'         => ['label' => 'Header',         'description' => 'Site header area'],
        'footer'         => ['label' => 'Footer',         'description' => 'Site footer area'],
        'navbar'         => ['label' => 'Navbar',         'description' => 'Navigation bar (theme picks position)'],
        'before-content' => ['label' => 'Before Content', 'description' => 'Above main content area'],
        'after-content'  => ['label' => 'After Content',  'description' => 'Below main content area'],
    ];

    public function __construct(Engine $app, PdoWrapper $pdo)
    {
        $this->app = $app;
        $this->pdo = $pdo;
    }

    /**
     * Get all available regions: platform + active theme's declared regions.
     *
     * @return array<string, array{label: string, description: string, source: string}>
     */
    public function getRegions(): array
    {
        $regions = [];

        // Platform regions
        foreach (self::PLATFORM_REGIONS as $id => $info) {
            $regions[$id] = [
                'label'       => $info['label'],
                'description' => $info['description'],
                'source'      => 'platform',
            ];
        }

        // Theme-declared regions from active theme's pubvana.json
        $themeRegions = $this->getThemeDeclaredRegions();
        foreach ($themeRegions as $region) {
            $id = $region['id'] ?? '';
            if ($id === '' || isset($regions[$id])) {
                continue;
            }
            $regions[$id] = [
                'label'       => $region['label'] ?? $id,
                'description' => $region['description'] ?? '',
                'source'      => 'theme',
            ];
        }

        return $regions;
    }

    /**
     * Get all available blocks registered via adext.
     *
     * @return array<string, array>
     */
    public function getAvailableBlocks(): array
    {
        return $this->app->adext('block', 'available') ?: [];
    }

    /**
     * Get placements for a specific region.
     *
     * @return BlockPlacement[]
     */
    public function getPlacements(string $regionId): array
    {
        return $this->placementModel()->getForRegion($regionId);
    }

    /**
     * Get all placements grouped by region.
     *
     * @return array<string, BlockPlacement[]>
     */
    public function getAllPlacements(): array
    {
        $all = $this->placementModel()->getAll();
        $grouped = [];

        foreach ($all as $placement) {
            $grouped[$placement->region_id][] = $placement;
        }

        return $grouped;
    }

    /**
     * Get saved values for a placement as nested array.
     */
    public function getPlacementValues(int $placementId): array
    {
        return $this->valueModel()->getNestedForPlacement($placementId);
    }

    /**
     * Save values for a placement. Deletes existing values and writes new ones.
     *
     * @param int   $placementId
     * @param array $values Flat dot-notation key => value pairs
     */
    public function savePlacementValues(int $placementId, array $values): void
    {
        $this->valueModel()->deleteForPlacement($placementId);

        $sort = 0;
        foreach ($values as $key => $value) {
            $this->valueModel()->saveValue($placementId, $key, (string) $value, $sort++);
        }
    }

    /**
     * Add a block to a region.
     */
    public function savePlacement(string $regionId, string $blockKey): void
    {
        $existing = $this->placementModel()->findPlacement($regionId, $blockKey);
        if ($existing) {
            return;
        }

        $placement = $this->placementModel();
        $placement->region_id = $regionId;
        $placement->block_key = $blockKey;
        $placement->sort_order = $this->placementModel()->nextSortOrder($regionId);
        $placement->created_at = date('Y-m-d H:i:s');
        $placement->insert();
    }

    /**
     * Remove a placement by ID.
     */
    public function removePlacement(int $id): void
    {
        $placement = $this->placementModel();
        $placement->eq('id', $id)->find();

        if ($placement->isHydrated()) {
            $placement->delete();
        }
    }

    /**
     * Move a placement from one region to another.
     */
    public function movePlacement(int $id, string $newRegionId): void
    {
        $placement = $this->placementModel();
        $placement->eq('id', $id)->find();

        if (!$placement->isHydrated()) {
            return;
        }

        // Check for duplicate in target region
        $existing = $this->placementModel()->findPlacement($newRegionId, $placement->block_key);
        if ($existing) {
            // Already placed in target region, just remove the old one
            $placement->delete();
            return;
        }

        $placement->region_id = $newRegionId;
        $placement->sort_order = $this->placementModel()->nextSortOrder($newRegionId);
        $placement->save();
    }

    /**
     * Reorder blocks within a region.
     *
     * @param string   $regionId
     * @param int[] $placementIds Ordered list of placement IDs
     */
    public function reorderPlacements(string $regionId, array $placementIds): void
    {
        foreach ($placementIds as $order => $id) {
            $placement = $this->placementModel();
            $placement->eq('id', (int) $id)
                      ->eq('region_id', $regionId)
                      ->find();

            if ($placement->isHydrated()) {
                $placement->sort_order = $order;
                $placement->save();
            }
        }
    }

    /**
     * Get placements whose region_id doesn't exist in the current region list.
     * These are orphaned when a theme switch removes theme-declared regions.
     *
     * @return BlockPlacement[]
     */
    public function getOrphanedPlacements(): array
    {
        $regions = $this->getRegions();
        $all = $this->placementModel()->getAll();
        $orphaned = [];

        foreach ($all as $placement) {
            if (!isset($regions[$placement->region_id])) {
                $orphaned[] = $placement;
            }
        }

        return $orphaned;
    }

    /**
     * Build rendered HTML for all regions.
     *
     * Queries placements for each region, calls each block's data provider,
     * renders the block template via Vision, concatenates per region.
     *
     * @return array<string, string> region_id => rendered HTML
     */
    public function buildAllRegions(): array
    {
        $regions = $this->getRegions();
        $blocks = $this->getAvailableBlocks();
        $output = [];

        foreach (array_keys($regions) as $regionId) {
            // Normalize hyphens to underscores for template access
            $key = str_replace('-', '_', $regionId);
            $output[$key] = $this->renderRegion($regionId, $blocks);
        }

        return $output;
    }

    /**
     * Render all placed blocks for a single region.
     */
    protected function renderRegion(string $regionId, array $blocks): string
    {
        $placements = $this->getPlacements($regionId);

        if (empty($placements)) {
            return '';
        }

        $view = $this->app->view();
        $vision = ($view instanceof PluginView) ? $view->vision() : null;

        if ($vision === null) {
            return '';
        }

        $html = '';

        foreach ($placements as $placement) {
            $block = $blocks[$placement->block_key] ?? null;

            if ($block === null) {
                continue;
            }

            $options = $this->valueModel()->getNestedForPlacement((int) $placement->id);
            $rendered = $this->renderBlock($block, $options, $vision, $view);
            if ($rendered !== '') {
                $html .= $rendered;
            }
        }

        return $html;
    }

    /**
     * Render a single block: call provider with saved values, resolve template, render with Vision.
     */
    protected function renderBlock(array $block, array $options, object $vision, PluginView $view): string
    {
        // Call the data provider with saved placement values
        $data = [];
        if (isset($block['provider']) && is_callable($block['provider'])) {
            try {
                $data = $block['provider']($options);
                if (!is_array($data)) {
                    $data = [];
                }
            } catch (\Throwable $e) {
                return '';
            }
        }

        // Resolve template path through the override chain
        $templatePath = $this->resolveBlockTemplate($block['template'] ?? '', $view);

        if ($templatePath === '' || !is_file($templatePath)) {
            return '';
        }

        try {
            return $vision->render($templatePath, $data);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Resolve a block template path through the three-tier override chain.
     *
     * Template format: 'pubvana/blog/public/blocks/recent-posts'
     * Resolution:
     *   1. app/views/pubvana/blog/public/blocks/recent-posts.tpl
     *   2. themes/{active}/Views/pubvana/blog/public/blocks/recent-posts.tpl
     *   3. vendor/pubvana/blog/src/Views/public/blocks/recent-posts.tpl
     */
    protected function resolveBlockTemplate(string $template, PluginView $view): string
    {
        if ($template === '') {
            return '';
        }

        // Parse package name from template path (first two segments)
        $parts = explode('/', $template);
        if (count($parts) < 3) {
            return '';
        }

        $packageName = $parts[0] . '/' . $parts[1];
        $relativePath = implode('/', array_slice($parts, 2)) . '.tpl';
        $prefixedPath = $template . '.tpl';

        // 1. App-level override
        $appViewsPath = $this->app->get('flight.views.path') ?? '.';
        $appOverride = $appViewsPath . DIRECTORY_SEPARATOR . $prefixedPath;
        if (is_file($appOverride)) {
            return $appOverride;
        }

        // 2. Theme override
        $themePath = $view->getThemePath();
        if ($themePath !== null) {
            $themeOverride = $themePath . DIRECTORY_SEPARATOR . $prefixedPath;
            if (is_file($themeOverride)) {
                return $themeOverride;
            }
        }

        // 3. Plugin default
        $pluginViewPath = $view->getPluginPath($packageName);
        if ($pluginViewPath !== null) {
            $pluginFile = $pluginViewPath . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($pluginFile)) {
                return $pluginFile;
            }
        }

        return '';
    }

    /**
     * Read theme-declared regions from the active theme's pubvana.json.
     */
    protected function getThemeDeclaredRegions(): array
    {
        $active = $this->app->themes()->getActive();
        if (!$active) {
            return [];
        }

        $root = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 5);
        $path = rtrim($root, '/') . '/themes/' . $active->folder . '/pubvana.json';

        if (!is_file($path)) {
            return [];
        }

        $info = json_decode(file_get_contents($path), true);
        return $info['provides']['regions'] ?? [];
    }

    protected function placementModel(): BlockPlacement
    {
        return new BlockPlacement($this->pdo);
    }

    protected function valueModel(): BlockPlacementValue
    {
        return new BlockPlacementValue($this->pdo);
    }
}
