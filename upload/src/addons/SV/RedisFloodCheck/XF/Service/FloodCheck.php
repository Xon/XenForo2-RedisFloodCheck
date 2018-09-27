<?php

namespace SV\RedisFloodCheck\XF\Service;

use SV\RedisCache\Redis;

class FloodCheck extends XFCP_FloodCheck
{
    /** @var string */
    const LUA_SHA1_SET_OR_GET_TTL = 'b670e66199af96236f9798dd1152e61c312d4f78';
    /** @var string */
    const LUA_SCRIPT_SET_OR_GET_TTL =
        "if not redis.call('SET', KEYS[1], '', 'NX', 'EX', ARGV[1]) then " .
        "return redis.call('TTL', KEYS[1]) " .
        "end " .
        "return 0 ";

    /**
     * @param string   $action
     * @param int      $userId
     * @param int|null $floodingLimit
     * @return int
     */
    public function checkFlooding($action, $userId, $floodingLimit = null)
    {
        $action = strval($action);
        $userId = intval($userId);
        if (!$userId)
        {
            return 0;
        }
        if ($floodingLimit === null)
        {
            $floodingLimit = $this->app->options()->floodCheckLength;
        }
        $floodingLimit = intval($floodingLimit);
        if ($floodingLimit <= 0)
        {
            return 0;
        }

        $app = $this->app;
        /** @var Redis $cache */
        $cache = $app->cache();
        if (!($cache instanceof Redis) || !($credis = $cache->getCredis(false)))
        {
            return parent::checkFlooding($action, $userId, $floodingLimit);
        }
        $useLua = $cache->useLua();
        $key = $cache->getNamespacedId('flood_' . strval($action) . '_' . strval($userId));

        // the key just needs to exist, not have any value
        if ($useLua)
        {
            $seconds = $credis->evalSha(static::LUA_SHA1_SET_OR_GET_TTL, [$key], [$floodingLimit]);
            if ($seconds === null)
            {
                $script = static::LUA_SCRIPT_SET_OR_GET_TTL;
                $seconds = $credis->eval($script, [$key], [$floodingLimit]);
            }
        }
        else
        {
            if (!$credis->set($key, '', ['NX', 'EX' => $floodingLimit]))
            {
                $seconds = $credis->ttl($key);
            }
            else
            {
                $seconds = 0;
            }
        }
        if ($seconds === 0)
        {
            return 0;
        }

        // seconds can return negative due to an error, treat that as requiring flooding
        return max(1, (int)$seconds);
    }
}