<?php

/**
 * modules/admin-dashboard/routes.php
 *
 * Included from AdminDashboardModule::routes().
 *
 * @var Router $router
 */

$router->get('/admin', function (): void {
    Auth::requireLogin(); // FIRST LINE — every /admin/* handler guards itself.
    AdminDashboardModule::dashboardHome();
});
