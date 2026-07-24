<?php

/**
 * modules/about/routes.php
 *
 * Included from AboutModule::routes(), so $router (the shared Router)
 * and $this (the module instance) are in scope here.
 *
 * @var Router $router
 */

$router->get('/about', function (): void {
    View::render(__DIR__ . '/views/about.php');
});
