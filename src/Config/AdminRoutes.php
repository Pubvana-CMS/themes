<?php

use Enlivenapp\FlightCsrf\Middlewares\CsrfMiddleware;
use Enlivenapp\FlightShield\Middlewares\PermissionMiddleware;
use Pubvana\Themes\Controllers\ThemesAdminController;

/** @var \flight\net\Router $router */
/** @var \flight\Engine $app */
/** @var string $configPrepend */

$permission = new PermissionMiddleware($app, 'themes.manage');
$csrf = new CsrfMiddleware($app);

$router->get('/themes', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->index();
})->addMiddleware($permission);

$router->post('/themes/@id/activate', function (string $id) use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->activate($id);
})->addMiddleware($permission)->addMiddleware($csrf);

$router->get('/themes/@id/options', function (string $id) use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->options($id);
})->addMiddleware($permission);

$router->post('/themes/@id/options', function (string $id) use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->saveOptions($id);
})->addMiddleware($permission)->addMiddleware($csrf);

$router->get('/themes/regions', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->regions();
})->addMiddleware($permission);

$router->post('/themes/regions/place', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->placeBlock();
})->addMiddleware($permission)->addMiddleware($csrf);

$router->post('/themes/regions/remove', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->removePlacement();
})->addMiddleware($permission)->addMiddleware($csrf);

$router->post('/themes/regions/reorder', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->reorderPlacements();
})->addMiddleware($permission)->addMiddleware($csrf);

$router->post('/themes/regions/move', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->movePlacement();
})->addMiddleware($permission)->addMiddleware($csrf);

$router->post('/themes/regions/values', function () use ($app, $configPrepend) {
    (new ThemesAdminController($app, $configPrepend))->saveBlockValues();
})->addMiddleware($permission)->addMiddleware($csrf);
