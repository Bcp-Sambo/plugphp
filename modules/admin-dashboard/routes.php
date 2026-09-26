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

/* ------------------------------------------------------------------ *
 * Updates.
 *
 * Applying an update is the single most powerful thing this dashboard can
 * do — it writes to core/. Both actions are POST, both call
 * Auth::requireLogin() first and Auth::requireCsrf() immediately after, and
 * neither is reachable by a GET. There is deliberately no cron entry point.
 * ------------------------------------------------------------------ */

$router->get('/admin/updates', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    AdminDashboardModule::updatesPage();
});

$router->post('/admin/updates/check', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::updatesPage(null, true);
});

$router->post('/admin/updates/apply', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::updatesPage(Updater::update());
});

$router->post('/admin/updates/rollback', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::updatesPage(Updater::rollback());
});

$router->post('/admin/updates/finish', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::updatesPage(Updater::finishInterrupted());
});

/* ------------------------------------------------------------------ *
 * Site settings — branding, SMTP, tracking.
 *
 * A fixed part of the dashboard shell. Every write is POST + login + CSRF;
 * the page itself is the only GET.
 * ------------------------------------------------------------------ */

$router->get('/admin/settings', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    AdminDashboardModule::settingsPage();
});

$router->post('/admin/settings/branding', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::saveBranding();
});

$router->post('/admin/settings/smtp', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::saveSmtp();
});

$router->post('/admin/settings/test-email', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::sendTestEmail();
});

$router->post('/admin/settings/tracking', function (): void {
    Auth::requireLogin();
    Auth::requireRole(Auth::ROLE_ADMIN);
    Auth::requireCsrf($_POST['csrf_token'] ?? null);
    AdminDashboardModule::saveTracking();
});
