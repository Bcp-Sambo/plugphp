<?php

/**
 * modules/auth/routes.php
 *
 * Included from AuthModule::routes(); $router and $this are in scope.
 *
 * @var Router $router
 */

$router->get('/login', function (): void {
    $this->showLogin();
});
$router->post('/login', function (): void {
    $this->handleLogin();
});

$router->post('/logout', function (): void {
    $this->handleLogout();
});

// Public registration is opt-in. When disabled, these routes are never
// registered, so /register naturally 404s (same pattern as the content
// modules' public-visibility toggle).
if (AuthModule::publicRegistrationEnabled()) {
    $router->get('/register', function (): void {
        $this->showRegister();
    });
    $router->post('/register', function (): void {
        $this->handleRegister();
    });
}

$router->get('/forgot-password', function (): void {
    $this->showForgotPassword();
});
$router->post('/forgot-password', function (): void {
    $this->handleForgotPassword();
});

$router->get('/reset-password', function (): void {
    $this->showResetPassword();
});
$router->post('/reset-password', function (): void {
    $this->handleResetPassword();
});

// Admin — always registered. Every handler re-checks login AND the admin
// role itself; the route file is not the guard.
$router->get('/admin/users', function (): void {
    $this->adminUsers();
});

$router->post('/admin/users', function (): void {
    $this->createUser();
});

$router->post('/admin/users/{id}/role', function (array $params): void {
    $this->changeUserRole($params['id']);
});

$router->get('/admin/users/{id}/delete', function (array $params): void {
    $this->confirmDeleteUser($params['id']);
});

$router->post('/admin/users/{id}/delete', function (array $params): void {
    $this->deleteUser($params['id']);
});
