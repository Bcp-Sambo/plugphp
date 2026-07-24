<?php

/**
 * Front controller.
 *
 * AI AGENTS: this file is core bootstrap, not a place to add
 * features. New pages/endpoints belong in a module's routes(),
 * registered below via the module loop — never appended here directly.
 */

require_once __DIR__ . '/../core/Config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Mailer.php';
require_once __DIR__ . '/../core/View.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Module.php';
require_once __DIR__ . '/../core/Settings.php';

Config::load(__DIR__ . '/../.env');

// Debug visibility follows .env — never show errors in production
if (Config::isDebug()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');
}

Auth::bootSession();
View::sendSecurityHeaders();

$router = new Router();

// Load only the modules enabled during install (written to config/modules.php by install.php)
$enabledModules = require __DIR__ . '/../config/modules.php';

foreach ($enabledModules as $moduleName) {
    // Folder names are kebab-case (contact-form, admin-dashboard); PHP class
    // names cannot contain hyphens, so convert to PascalCase:
    // contact-form -> ContactFormModule, blog -> BlogModule.
    $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $moduleName))) . 'Module';
    $modulePath = __DIR__ . "/../modules/{$moduleName}/{$className}.php";

    if (!file_exists($modulePath)) {
        continue;
    }

    require_once $modulePath;
    /** @var Module $module */
    $module = new $className();
    $module->routes($router);
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
