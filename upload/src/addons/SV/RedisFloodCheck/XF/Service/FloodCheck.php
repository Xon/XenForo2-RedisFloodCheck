<?php

namespace SV\RedisFloodCheck\XF\Service;

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

        $cache = $this->app->cache();
        if (!$cache || !method_exists($cache, 'getCredis') || !($credis = $cache->getCredis($cache)))
        {
            return parent::checkFlooding($action, $userId, $floodingLimit);
        }
        $useLua = method_exists($cache, 'useLua') && $cache->useLua();
        $key = $this->app->config['cache']['namespace'] . '[flood]['.strval($action).']['.strval($userId).']';

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
                return 0;
            }
            $seconds = $credis->ttl($key);
        }
        // seconds can return negative due to an error, treat that as requiring flooding
        return max(1, $seconds);
    }
}