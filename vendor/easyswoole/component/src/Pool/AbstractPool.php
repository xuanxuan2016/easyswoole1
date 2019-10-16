<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/6/22
 * Time: 下午1:21
 */

namespace EasySwoole\Component\Pool;


use EasySwoole\Component\Pool\Exception\PoolObjectNumError;
use EasySwoole\Utility\Random;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * 连接池抽象类
 * 1.连接池的基类，用于创建各种连接池
 */
abstract class AbstractPool
{
    /**
     * 复用连接池调用的类
     */
    use TraitInvoker;
    
    /**
     * 已创建的对象数
     */
    private $createdNum = 0;
    
    /**
     * 连接池通道，用于存放具体的对象，如objRedis
     */
    private $poolChannel;
    
    /**
     *连接池中对象的hash值，用于维护连接池中对象的使用 
     */
    private $objHash = [];
    
    /**
     * 连接池配置信息
     */
    private $conf;
    
    /**
     * 连接池的定时器
     */
    private $timerId;
    
    /**
     * 连接池是否已销毁，如果销毁了需要回收所有对象
     */
    private $destroy = false;

    /*
     * 如果成功创建了,请返回对应的obj
     * 用于创建具体的连接对象，需要被实现
     */
    abstract protected function createObject();
    
    /**
     * 构造函数
     */
    public function __construct(PoolConf $conf)
    {
        //连接池最小值不能大于最大值
        if ($conf->getMinObjectNum() >= $conf->getMaxObjectNum()) {
            $class = static::class;
            //抛出异常
            throw new PoolObjectNumError("pool max num is small than min num for {$class} error");
        }
        $this->conf = $conf;
        //定义连接池管道
        //+8，给通道增加一个余量防止极限情况
        $this->poolChannel = new Channel($conf->getMaxObjectNum() + 8);
        if ($conf->getIntervalCheckTime() > 0) {
            //设置定时器，移除空闲时间大于[maxIdleTime]的连接对象，并维持[minObjectNum]最小连接对象
            $this->timerId = Timer::tick($conf->getIntervalCheckTime(), [$this, 'intervalCheck']);
        }
    }

    /*
     * 回收连接池对象
     */
    public function recycleObj($obj): bool
    {
        /**
         * 连接池被销毁，需要销毁连接池对象
         */
        if($this->destroy){
            $this->unsetObj($obj);
            return true;
        }
        /*
         * 只允许回收属于本pool且不在pool内的对象
         */
        if($this->isPoolObject($obj) && (!$this->isInPool($obj))){
            $hash = $obj->__objHash;
            //标记为在pool内
            $this->objHash[$hash] = true;
            if($obj instanceof PoolObjectInterface){
                try{
                    $obj->objectRestore();
                }catch (\Throwable $throwable){
                    //重新标记为非在pool状态,允许进行unset
                    $this->objHash[$hash] = false;
                    $this->unsetObj($obj);
                    throw $throwable;
                }
            }
            //连接池对象重新进入通道
            $this->poolChannel->push($obj);
            return true;
        }else{
            return false;
        }
    }

    /*
     * 获取连接池对象
     * 1.tryTimes为获取对象的重试次数
     */
    public function getObj(float $timeout = null, int $tryTimes = 3)
    {
        //连接池是否已被销毁
        if($this->destroy){
            return null;
        }
        //从连接池中获取对象的超时时间
        if($timeout === null){
            $timeout = $this->getConfig()->getGetObjectTimeout();
        }
        $object = null;
        //如果通道为空，则创建对象
        if($this->poolChannel->isEmpty()){
            try{
                $this->initObject();
            }catch (\Throwable $throwable){
                if($tryTimes <= 0){
                    throw $throwable;
                }else{
                    $tryTimes--;
                    return $this->getObj($timeout,$tryTimes);
                }
            }
        }
        //从通道获取对象
        $object = $this->poolChannel->pop($timeout);
        if(is_object($object)){
            if($object instanceof PoolObjectInterface){
                try{
                    if($object->beforeUse() === false){
                        //对象已被弃用，销毁对象重新尝试获取
                        $this->unsetObj($object);
                        if($tryTimes <= 0){
                            return null;
                        }else{
                            $tryTimes--;
                            return $this->getObj($timeout,$tryTimes);
                        }
                    }
                }catch (\Throwable $throwable){
                    //出现异常，销毁对象重新尝试获取
                    $this->unsetObj($object);
                    if($tryTimes <= 0){
                        throw $throwable;
                    }else{
                        $tryTimes--;
                        return $this->getObj($timeout,$tryTimes);
                    }
                }
            }
            $hash = $object->__objHash;
            //标记该对象已经被使用，不在pool中
            $this->objHash[$hash] = false;
            $object->__lastUseTime = time();
            return $object;
        }else{
            return null;
        }
    }

    /*
     * 彻底释放一个连接池对象
     */
    public function unsetObj($obj): bool
    {
        if($this->isPoolObject($obj) && (!$this->isInPool($obj))){
            $hash = $obj->__objHash;
            unset($this->objHash[$hash]);
            if($obj instanceof PoolObjectInterface){
                try{
                    $obj->gc();
                }catch (\Throwable $throwable){
                    throw $throwable;
                }finally{
                    $this->createdNum--;
                }
            }else{
                $this->createdNum--;
            }
            return true;
        }else{
            return false;
        }
    }

    /*
     * 回收空闲时间超过[maxIdleTime]的连接池对象
     */
    public function idleCheck(int $idleTime)
    {
        $list = [];
        //遍历通道中未使用的对象
        while (!$this->poolChannel->isEmpty()){
            //外部getObj，拿走了最后一个
            //定时器执行时，下面拿不到了
            $item = $this->poolChannel->pop(0.01);
            if($item!==false){                
                if(time() - $item->__lastUseTime > $idleTime){
                    //标记为不在队列内，允许进行gc回收
                    $hash = $item->__objHash;
                    $this->objHash[$hash] = false;
                    $this->unsetObj($item);
                }else{
                    $list[] = $item;
                }
            }
        }
        //未过期对象重新进入通道
        foreach ($list as $item){
            $this->poolChannel->push($item);
        }
    }

    /*
     * 定时维护连接池
     * 1.回收空闲时间超过[maxIdleTime]的连接池对象
     * 2.保持连接池内对象不能小于最小对象数
     */
    public function intervalCheck()
    {
        $this->idleCheck($this->getConfig()->getMaxIdleTime());
        $this->keepMin($this->getConfig()->getMinObjectNum());
    }
    
    /**
     * 保持连接池内对象为最小对象数
     */
    public function keepMin(?int $num = null): int
    {
        if($this->createdNum < $num){
            $left = $num - $this->createdNum;
            while ($left > 0 ){
                $this->initObject();
                $left--;
            }
        }
        return $this->createdNum;
    }

    /*
     * 用以解决冷启动问题,其实是是keepMin别名
    */
    public function preLoad(?int $num = null): int
    {
        return $this->keepMin($num);
    }

    /**
     *获取连接池配置 
     */
    public function getConfig():PoolConf
    {
        return $this->conf;
    }
    
    /**
     * 获取连接池状态
     * 1.连接池中已创建的对象数
     * 2.正在使用的对象数
     * 3.最大可创建对象数
     * 4.最小可创建对象数
     */
    public function status()
    {
        return [
            'created' => $this->createdNum,
            'inuse' => $this->createdNum - $this->poolChannel->stats()['queue_num'],
            'max' => $this->getConfig()->getMaxObjectNum(),
            'min' => $this->getConfig()->getMinObjectNum()
        ];
    }
    
    /**
     * 初始化连接池对象
     */
    private function initObject():bool
    {
        $obj = null;
        
        //判断是否已达到最大可创建数量(此种写法是先占用位置，而不是等到创建结束再++)
        $this->createdNum++;
        if($this->createdNum > $this->getConfig()->getMaxObjectNum()){
            $this->createdNum--;
            return false;
        }
        //创建对象
        try{
            $obj = $this->createObject();
            if(is_object($obj)){
                //hash key
                $hash = Random::character(12);
                $this->objHash[$hash] = true;
                $obj->__objHash = $hash;
                $obj->__lastUseTime = time();
                //将对象添加到管道
                $this->poolChannel->push($obj);
                return true;
            }else{
                $this->createdNum--;
            }
        }catch (\Throwable $throwable){
            $this->createdNum--;
            throw $throwable;
        }
        return false;
    }
    
    /**
     * 对象是否属于当前连接池
     * 1.判断依据对象是否有[__objHash]属性，且在[$objHash]数组中
     */
    public function isPoolObject($obj):bool
    {
        if(isset($obj->__objHash)){
            return isset($this->objHash[$obj->__objHash]);
        }else{
            return false;
        }
    }
    
    /**
     * 对象是否在连接池中
     */
    public function isInPool($obj):bool
    {
        if($this->isPoolObject($obj)){
            return $this->objHash[$obj->__objHash];
        }else{
            return false;
        }
    }
    
    /**
     * 销毁连接池
     */
    function destroyPool()
    {
        $this->destroy = true;
        //销毁连接池的定时器
        if($this->timerId && Timer::exists($this->timerId)){
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
        //释放连接池中的对象
        while (!$this->poolChannel->isEmpty()){
            $item = $this->poolChannel->pop(0.01);
            $this->unsetObj($item);
        }
    }

}
