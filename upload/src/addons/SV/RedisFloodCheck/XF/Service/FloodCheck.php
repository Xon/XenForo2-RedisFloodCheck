<?php

declare(strict_types=1);

namespace SV\RedisFloodCheck\XF\Service;

use SV\RedisCache\Repository\Redis as RedisRepo;

use function is_float;
use function is_int;
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
     * @param string         $action
     * @param int            $userId
     * @param float|int|null $floodingLimit
     * @return int
     * @noinspection PhpMissingReturnTypeInspection
     */
    public function checkFlooding($action, $userId, $floodingLimit = null)
    {
        $userId = (int)$userId;
        if ($userId === 0)
        {
            return 0;
        }
        if ($floodingLimit === null)
        {
            $floodingLimit = \XF::options()->floodCheckLength ?? 0;
        }
        else if (is_float($floodingLimit))
        {
            $floodingLimit = (int)min(1, (int)round($floodingLimit, 0, PHP_ROUND_HALF_UP));
        }
        if ($floodingLimit <= 0)
        {
            return 0;
        }

        /** @var int $floodingLimit */

        $cache = RedisRepo::get()->getRedisConnector();
        if ($cache === null || !($credis = $cache->getCredis()))
        {
            return parent::checkFlooding($action, $userId, $floodingLimit);
        }
        $key = $cache->getNamespacedId('flood_' . $action . '_' . $userId);

        // convert from seconds to integer milliseconds
        $floodingLimit = $floodingLimit * 1000;

        if (\XF::$developmentMode)
        {
            $expectedHash = sha1($this->lua_get_or_set_pttl_script);
            if ($this->lua_get_or_set_pttl_sha1 !== $expectedHash)
            {
                throw new \LogicException('Flood-check lua sha1 does not match, expected: ' . $expectedHash);
            }
        }
        // the key just needs to exist, not have any value
        $milliseconds = $credis->evalSha($this->lua_get_or_set_pttl_sha1, [$key], [$floodingLimit]);
        if ($milliseconds === null)
        {
            $script = $this->lua_get_or_set_pttl_script;
            $milliseconds = $credis->eval($script, [$key], [$floodingLimit]);
        }
        if (is_float($milliseconds))
        {
            $milliseconds = (int)$milliseconds;
        }
        if (!is_int($milliseconds) || $milliseconds < 0)
        {
            // seconds can return negative due to an error, treat that as requiring flooding
            return 1;
        }

        if ($milliseconds === 0)
        {
            return 0;
        }

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $seconds = (int)round($milliseconds / 1000, 0, PHP_ROUND_HALF_UP);

        return $seconds;
    }
}