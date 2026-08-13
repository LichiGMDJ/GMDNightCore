<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\Config;
use NightCore\Web\Localization\WebLocalizer;

require_once __DIR__ . '/autoload.php';

Config::loadEnv(__DIR__ . '/.env');
WebLocalizer::bootForCurrentScript();

return Application::boot();
