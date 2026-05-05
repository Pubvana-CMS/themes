<?php

declare(strict_types=1);

namespace Pubvana\Themes;

use Enlivenapp\FlightSchool\PluginInterface;
use Pubvana\Themes\Services\RegionManager;
use Pubvana\Themes\Services\ThemeService;
use flight\Engine;
use flight\net\Router;
use Flight;

/**
 * Themes plugin — registers theme and region services, admin menus, and dashboard cards.
 */
class Plugin implements PluginInterface
{
    public function register(Engine $app, Router $router, array $config = []): void
    {
        $app->map('themes', function () {
            static $instance = null;
            if ($instance === null) {
                $instance = new ThemeService(Flight::db());
            }
            return $instance;
        });

        $app->map('regions', function () use ($app) {
            static $instance = null;
            if ($instance === null) {
                $instance = new RegionManager($app, Flight::db());
            }
            return $instance;
        });

        $activeTheme = $app->themes()->getActive();
        if ($activeTheme !== null && !empty($activeTheme->folder)) {
            $folder = (string) $activeTheme->folder;
            $dest = $app->themes()->getPublicPath() . 'themes/' . $folder . '/assets';
            if (!is_dir($dest)) {
                $app->themes()->publishAssets($folder);
            }
        }

        // Publish package assets on first run (directory missing)
        $cssEntries = $app->adext('head', 'css') ?: [];
        $jsEntries = $app->adext('footer', 'js') ?: [];
        $allEntries = array_merge($cssEntries, $jsEntries);
        if (!empty($allEntries)) {
            $app->themes()->publishPackageAssets($allEntries, false);
        }

        $app->adext('menu', 'appearance', 'pubvana.themes', [
            'label'    => 'Themes',
            'icon'     => 'ti-palette',
            'url'      => '/themes',
            'priority' => 10,
            'submenu'  => [
                'list' => [
                    'label'    => 'All Themes',
                    'url'      => '/themes',
                    'priority' => 10,
                ],
                'regions' => [
                    'label'    => 'Regions',
                    'url'      => '/themes/regions',
                    'priority' => 20,
                ],
            ],
        ]);
    }
}
