<?php

/**
 * AboutModule
 *
 * Static content module — no database table, no dashboard panel
 * (Option A, decided in the PRD). Content lives directly in the view.
 */
final class AboutModule extends Module
{
    public function name(): string
    {
        return 'about';
    }

    public function label(): string
    {
        return 'About Page';
    }

    public function routes(Router $router): void
    {
        // routes.php has access to $router and $this (see routes() scope).
        require __DIR__ . '/routes.php';
    }

    /** No tables — static module. */
    public function migrations(): array
    {
        return [];
    }

    // dashboardNavItem() intentionally not overridden: this module has no
    // admin presence (Option A), so it inherits the base null.
}
