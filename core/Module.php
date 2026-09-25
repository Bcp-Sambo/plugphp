<?php

/**
 * Module (abstract base class)
 *
 * Every folder under /modules/* must contain one class extending
 * this, named to match its folder (e.g. modules/blog/BlogModule.php).
 * This does not stop bad code inside a module, but it forces every
 * module to expose the same shape so the bootstrap, the installer,
 * and any future tooling can treat all modules uniformly.
 *
 * AI AGENTS: when adding a new module, extend this class. Do not
 * invent a different registration pattern. Do not skip migrate()
 * even if the module has no tables (return an empty array).
 */
abstract class Module
{
    /** Machine-readable name, must match the folder name. */
    abstract public function name(): string;

    /** Human-readable label shown in the install GUI's module picker. */
    abstract public function label(): string;

    /** Register this module's routes on the shared Router instance. */
    abstract public function routes(Router $router): void;

    /**
     * Absolute paths to this module's *.sql migration files,
     * run once in order during install. Return [] if none needed.
     */
    abstract public function migrations(): array;

    /**
     * Optional: dashboard nav entry this module contributes.
     * Return null if this module has no admin-dashboard presence.
     */
    public function dashboardNavItem(): ?array
    {
        return null;
    }

    /**
     * Optional: public site nav entry this module contributes.
     *
     * Return ['label' => 'Blog', 'url' => '/blog'] — plus 'primary' => true
     * to render as the highlighted call-to-action button. Return null when a
     * module has no public-facing page (auth, admin-dashboard).
     *
     * Nav::publicItems() drops entries whose module has been hidden from the
     * dashboard, so a module does NOT need to check its own visibility here.
     *
     * AI AGENTS: a module's public links belong here, not hardcoded into
     * resources/layout.php. A hardcoded link survives the module being
     * disabled and points visitors at a 404.
     */
    public function publicNavItem(): ?array
    {
        return null;
    }
}
