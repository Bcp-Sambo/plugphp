<?php

/**
 * modules/blog/routes.php
 *
 * Included from BlogModule::routes(); $router and $this are in scope.
 * Public routes register only when visible; admin routes always register.
 *
 * @var Router $router
 */

if (Settings::isModuleVisible('blog')) {
    $router->get('/blog', function (): void {
        $this->publicIndex();
    });
    $router->get('/blog/{slug}', function (array $params): void {
        $this->publicShow($params['slug']);
    });
}

// ----- Admin (always registered) -----
$router->get('/admin/blog', function (): void {
    $this->adminIndex();
});
$router->get('/admin/blog/new', function (): void {
    $this->adminNew();
});
$router->post('/admin/blog', function (): void {
    $this->adminStore();
});
$router->post('/admin/blog/visibility', function (): void {
    $this->adminToggleVisibility();
});
$router->get('/admin/blog/{id}/edit', function (array $params): void {
    $this->adminEdit($params['id']);
});
$router->post('/admin/blog/{id}', function (array $params): void {
    $this->adminUpdate($params['id']);
});
$router->get('/admin/blog/{id}/delete', function (array $params): void {
    $this->adminDeleteConfirm($params['id']);
});
$router->post('/admin/blog/{id}/delete', function (array $params): void {
    $this->adminDelete($params['id']);
});
