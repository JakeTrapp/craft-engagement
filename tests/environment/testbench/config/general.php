<?php

use craft\config\GeneralConfig;
use craft\helpers\App;

return GeneralConfig::create()
    ->omitScriptNameInUrls(true)
    ->allowAdminChanges(App::env('CRAFT_ALLOW_ADMIN_CHANGES') !== false)
    ->devMode(App::env('CRAFT_DEV_MODE') !== false)
    ->securityKey(App::env('CRAFT_SECURITY_KEY') ?: 'engagement-testbench-not-for-production')
    ->aliases(['@web' => App::env('PRIMARY_SITE_URL') ?: 'https://craft-engagement-testbench.ddev.site']);
