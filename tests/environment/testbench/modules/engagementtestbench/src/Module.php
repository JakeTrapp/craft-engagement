<?php

declare(strict_types=1);

namespace modules\engagementtestbench;

use Craft;
use yii\base\Module as BaseModule;

/**
 * Testbench-only module. Never shipped with the plugin; provides the fixture seed command.
 */
final class Module extends BaseModule
{
    public function init(): void
    {
        Craft::setAlias('@modules/engagementtestbench', __DIR__);
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\engagementtestbench\\console\\controllers';
        }
        parent::init();
    }
}
