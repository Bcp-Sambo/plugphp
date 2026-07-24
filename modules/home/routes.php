<?php

/**
 * modules/home/routes.php
 *
 * Included from HomeModule::routes(); $router and $this are in scope.
 *
 * @var Router $router
 */

$router->get('/', function (): void {
    $this->show();
});
