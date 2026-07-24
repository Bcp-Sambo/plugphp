<?php

/**
 * modules/projects/routes.php
 *
 * Included from ProjectsModule::routes(); $router and $this are in scope.
 * Public routes register only when visible; admin routes always register.
 *
 * @var Router $router
 */

if (Settings::isModuleVisible('projects')) {
    $router->get('/projects', function (): void {
        $this->publicIndex();
    });
    $router->get('/projects/{slug}', function (array $params): void {
        $this->publicShow($params['slug']);
    });
}

// ----- Admin (always registered) -----
$router->get('/admin/projects', function (): void {
    $this->adminIndex();
});
$router->get('/admin/projects/new', function (): void {
    $this->adminNew();
});
$router->post('/admin/projects', function (): void {
    $this->adminStore();
});
$router->post('/admin/projects/visibility', function (): void {
    $this->adminToggleVisibility();
});
$router->get('/admin/projects/{id}/edit', function (array $params): void {
    $this->adminEdit($params['id']);
});
$router->post('/admin/projects/{id}', function (array $params): void {
    $this->adminUpdate($params['id']);
});
$router->get('/admin/projects/{id}/delete', function (array $params): void {
    $this->adminDeleteConfirm($params['id']);
});
$router->post('/admin/projects/{id}/delete', function (array $params): void {
    $this->adminDelete($params['id']);
});
