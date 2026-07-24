<?php

/**
 * modules/contact-form/routes.php
 *
 * Included from ContactFormModule::routes(); $router and $this are in scope.
 *
 * @var Router $router
 */

$router->get('/contact', function (): void {
    $this->showForm();
});

$router->post('/contact', function (): void {
    $this->handleSubmit();
});

// Admin — always registered (not gated by any public-visibility toggle).
$router->get('/admin/messages', function (): void {
    $this->adminMessages();
});
