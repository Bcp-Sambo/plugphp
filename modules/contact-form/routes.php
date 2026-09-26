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

$router->get('/admin/messages/{id}', function (array $params): void {
    $this->adminMessage($params['id']);
});

$router->post('/admin/messages/{id}/reply', function (array $params): void {
    $this->replyToMessage($params['id']);
});
