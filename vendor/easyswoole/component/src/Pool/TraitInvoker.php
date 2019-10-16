<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2019-01-06
 * Time: 19:22
 */

namespace EasySwoole\Component\Pool;


use EasySwoole\Component\Context\ContextManager;
use EasySwoole\Component\Pool\Exception\PoolEmpty;
use EasySwoole\Component\Pool\Exception\PoolException;
use Swoole\Coroutine;

trait TraitInvoker
{
    /**
     * 传入回调来使用连接池对象，使用完会自动回收对象
     */
    public static function invoke(callable $call,float $timeout = null)
    {
        $pool = PoolManager::getInstance()->getPool(static::class);
        if($pool instanceof AbstractPool){
            $obj = $pool->getObj($timeout);
            if($obj){
                try{
                    //调用传入的方法
                    $ret = call_user_func($call,$obj);
                    return $ret;
                }catch (\Throwable $throwable){
                    throw $throwable;
                }finally{
                    //回收对象
                    $pool->recycleObj($obj);
                }
            }else{
                throw new PoolEmpty(static::class." pool is empty");
            }
        }else{
            throw new PoolException(static::class." convert to pool error");
        }
    }
    
    /**
     * 获取一个连接,协程结束后自动回收
     */
    public static function defer($timeout = null)
    {
        $key = md5(static::class);
        $obj = ContextManager::getInstance()->get($key);
        if($obj){
            return $obj;
        }else{
            $pool = PoolManager::getInstance()->getPool(static::class);
            if($pool instanceof AbstractPool){
                $obj = $pool->getObj($timeout);
                if($obj){
                    Coroutine::defer(function ()use($pool,$obj){
                        $pool->recycleObj($obj);
                    });
                    ContextManager::getInstance()->set($key,$obj);
                    return $obj;
                }else{
                    throw new PoolEmpty(static::class." pool is empty");
                }
            }else{
                throw new PoolException(static::class." convert to pool error");
            }
        }
    }
}