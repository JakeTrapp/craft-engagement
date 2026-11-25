<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    ->allowAdminChanges(true)
    ->devMode(true)
    ->securityKey('engagement-automated-tests-only')
    ->testToEmailAddress('engagement-tests@example.test');
