<?php

namespace SV\RedisFloodCheck\XF\Service;

use SV\RedisCache\Redis;

class FloodCheck extends XFCP_FloodCheck
{
    const LUA_SETTTL_SH1 = 'b670e66199af96236f9798dd1152e61c312d4f78';

    public function checkFlooding($action, $userId, $floodingLimit = null)
    {
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
        $key = $cache->getNamespacedId('flood_'.strval($action).'_'.strval($userId));

        // the key just needs to exist, not have any value
        if ($useLua)
        {
            $seconds = $credis->evalSha(self::LUA_SETTTL_SH1, array($key), array($floodingLimit));
            if ($seconds === null)
            {
                $script =
                    "if not redis.call('SET', KEYS[1], '', 'NX', 'EX', ARGV[1]) then ".
                        "return redis.call('TTL', KEYS[1]) ".
                    "end ".
                    "return 0 ";
                $seconds = $credis->eval($script, array($key), array($floodingLimit));
            }
            if ($seconds === 0)
            {
                return 0;
            }
        }
        else
        {
            if (!$credis->set($key, '', array('nx', 'ex'=> $floodingLimit)))
            {
                return $credis->ttl($key);
            }
            return 0;
        }
        // seconds can return negative due to an error, treat that as requiring flooding
        return max(1, $seconds);
    }
}