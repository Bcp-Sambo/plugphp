<?php

/**
 * modules/services/routes.php
 *
 * Included from ServicesModule::routes(); $router and $this are in scope.
 *
 * Public routes are registered ONLY when the module is publicly visible.
 * When hidden, they are never registered, so the router's own 404 handles
 * the hidden case with no special branch (see root SKILL.md). Admin routes
 * are ALWAYS registered so a hidden module stays fully editable.
 *
 * @var Router $router
 */

if (Settings::isModuleVisible('services')) {
    $router->get('/services', function (): void {
        $this->publicIndex();
    });
    $router->get('/services/{slug}', function (array $params): void {
        $this->publicShow($params['slug']);
    });
}

// ----- Admin (always registered) -----
$router->get('/admin/services', function (): void {
    $this->adminIndex();
});
$router->get('/admin/services/new', function (): void {
    $this->adminNew();
});
$router->post('/admin/services', function (): void {
    $this->adminStore();
});
$router->post('/admin/services/visibility', function (): void {
    $this->adminToggleVisibility();
});
$router->get('/admin/services/{id}/edit', function (array $params): void {
    $this->adminEdit($params['id']);
});
$router->post('/admin/services/{id}', function (array $params): void {
    $this->adminUpdate($params['id']);
});
$router->get('/admin/services/{id}/delete', function (array $params): void {
    $this->adminDeleteConfirm($params['id']);
});
$router->post('/admin/services/{id}/delete', function (array $params): void {
    $this->adminDelete($params['id']);
});
