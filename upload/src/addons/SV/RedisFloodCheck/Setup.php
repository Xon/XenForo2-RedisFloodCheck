<?php


namespace SV\RedisFloodCheck;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use SV\RedisCache\Redis;

/**
 * Add-on installation, upgrade, and uninstall routines.
 */
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function checkRequirements(&$errors = [], &$warnings = []): void
    {
        $cache = \XF::app()->cache();
        if (!($cache instanceof Redis) || !$cache->getCredis())
        {
            $warnings[] = 'This add-on requires Redis Cache to be installed and configured';
        }
    }
}
