<?php

use modules\engagementtestbench\Module;

return [
    'id' => getenv('CRAFT_APP_ID') ?: 'engagement-testbench',
    'modules' => ['engagement-testbench' => Module::class],
    'bootstrap' => ['engagement-testbench'],
    'components' => [
        'projectConfig' => [
            'class' => craft\services\ProjectConfig::class,
            'writeYamlAutomatically' => false,
        ],
    ],
];
