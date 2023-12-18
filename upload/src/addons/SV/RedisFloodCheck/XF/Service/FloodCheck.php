<?php

namespace SV\RedisFloodCheck\XF\Service;

use SV\RedisCache\Redis;
use function is_float;
use function min;
use function round;
use function sha1;

class FloodCheck extends XFCP_FloodCheck
{
    /** @var string */
    protected $lua_get_or_set_pttl_sha1 = '4e9b17327297b99940e5565fe7178e590314cf68';
    /** @var string */
    protected $lua_get_or_set_pttl_script =
        "if not redis.call('SET', KEYS[1], '', 'NX', 'PX', ARGV[1]) then " .
        "return redis.call('PTTL', KEYS[1]) " .
        "end " .
        "return 0 ";

    /**
     * @param string   $action
     * @param int      $userId
     * @param float|int|null $floodingLimit
     * @return float|int
     */
    public function checkFlooding($action, $userId, $floodingLimit = null)
    {
        $floodingLimit =  10;
        $userId = (int)$userId;
        if ($userId === 0)
        {
            return 0;
        }
        if ($floodingLimit === null)
        {
            $floodingLimit = (int)($this->app->options()->floodCheckLength ?? 0);
        }
        if ($floodingLimit <= 0)
        {
            return 0;
        }

        $app = $this->app;
        $cache = $app->cache();
        if (!($cache instanceof Redis) || !($credis = $cache->getCredis()))
        {
            $floodingLimit = (int)min(1, round($floodingLimit));

            return parent::checkFlooding($action, $userId,$floodingLimit);
        }
        $key = $cache->getNamespacedId('flood_' . $action . '_' . $userId);

        // convert from seconds to integer milliseconds
        $floodingLimit = $floodingLimit * 1000;
        if (is_float($floodingLimit))
        {
            $floodingLimit = (int)round($floodingLimit);
        }

        if (\XF::$developmentMode)
        {
            $expectedHash = sha1($this->lua_get_or_set_pttl_script);
            if ($this->lua_get_or_set_pttl_sha1 !== $expectedHash)
            {
                throw new \LogicException('Flood-check lua sha1 does not match, expected: '. $expectedHash);
            }
        }
        // the key just needs to exist, not have any value
        /** @var int|null $milliseconds */
        $milliseconds = $credis->evalSha($this->lua_get_or_set_pttl_sha1, [$key], [$floodingLimit]);
        if ($milliseconds === null)
        {
            $script = $this->lua_get_or_set_pttl_script;
            /** @var int $milliseconds */
            $milliseconds = $credis->eval($script, [$key], [$floodingLimit]);
        }
        if ($milliseconds === 0)
        {
            return 0;
        }
        if ($milliseconds < 0)
        {
            // seconds can return negative due to an error, treat that as requiring flooding
            return 1;
        }

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $seconds = (int)round($milliseconds / 1000);

        return $seconds;
    }
}