<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/7/26
 * Time: 上午12:54
 */

namespace EasySwoole\Component\Pool;


use EasySwoole\Component\Pool\Exception\PoolException;
use EasySwoole\Component\Singleton;
use EasySwoole\Utility\Random;

/**
 * 连接池管理类
 */
class PoolManager
{
    use Singleton;

    private $poolRegister = [];
    private $pool = [];
    private $defaultConfig;
    private $anonymousMap = [];
    
    /**
     * 构造函数
     */
    function __construct()
    {
        $this->defaultConfig = new PoolConf();
    }
    
    /**
     * 获取配置信息
     */
    function getDefaultConfig()
    {
        return $this->defaultConfig;
    }
    
    /**
     * 连接池注册
     */
    function register(string $className, $maxNum = 20):PoolConf
    {
        $ref = new \ReflectionClass($className);
        if($ref->isSubclassOf(AbstractPool::class)){
            $conf = clone $this->defaultConfig;
            $conf->setMaxObjectNum($maxNum);
            //记录注册信息
            $this->poolRegister[$className] = [
                'class'=>$className,
                'config'=>$conf
            ];
            return $conf;
        }else{
            throw new PoolException("class {$className} not a sub class of AbstractPool class");
        }
    }
    
    /**
     * 注册匿名连接池
     */
    function registerAnonymous(string $name,?callable $createCall = null)
    {
        // 拒绝相同名称的池重复注册
        if (isset($this->poolRegister[$name])) {
            return true;
        }
        /*
         * 绕过去实现动态class
         */
        $class = 'C'.Random::character(16);
        $classContent = '<?php
        class '.$class.' extends \EasySwoole\Component\Pool\AbstractPool {
            private $call;
            function __construct($conf,$call)
            {
                $this->call = $call;
                parent::__construct($conf);
            }

            protected function createObject()
            {
                // TODO: Implement createObject() method.
                return call_user_func($this->call,$this->getConfig());
            }
        }';
        $file = sys_get_temp_dir()."/{$class}.php";
        file_put_contents($file,$classContent);
        require_once $file;
        unlink($file);
        if(!is_callable($createCall)){
            if(class_exists($name)){
                $createCall = function ()use($name){
                    return new $name;
                };
            }else{
                return false;
            }
        }
        $this->poolRegister[$name] = [
            'class'=>$class,
            'call'=>$createCall,
        ];
        return true;
    }

    /*
     * 请在进程克隆后，也就是worker start后，每个进程中独立使用
     * 获取连接池
     */
    function getPool(string $key):?AbstractPool
    {   
        //已存在连接池，直接返回
        if(isset($this->anonymousMap[$key])){
            $key = $this->anonymousMap[$key];
        }
        if(isset($this->pool[$key])){
            return $this->pool[$key];
        }
        //存在注册信息
        if(isset($this->poolRegister[$key])){
            $item = $this->poolRegister[$key];
            if($item instanceof AbstractPool){
                return $item;
            }else{
                $class = $item['class'];
                if(isset($item['config'])){
                    //创建正常连接池
                    $obj = new $class($item['config']);
                    $this->pool[$key] = $obj;
                }else if(isset($item['call'])){
                    //创建匿名连接池
                    $config = clone $this->defaultConfig;
                    $createCall = $item['call'];
                    $obj = new $class($config,$createCall);
                    $this->pool[$key] = $obj;
                    $this->anonymousMap[get_class($obj)] = $key;
                }else{
                    return null;
                }
                return $this->getPool($key);
            }
        }else{
            //先尝试动态注册
            $ret = false;
            try{
                $ret = $this->register($key);
            }catch (\Throwable $throwable){
                //此处异常不向上抛。
            }
            if($ret){
                return $this->getPool($key);
            }else if(class_exists($key) && $this->registerAnonymous($key)){
                return $this->getPool($key);
            }
            return null;
        }
    }
    
    /**
     * 清除所有的连接池
     */
    public function clearPool():PoolManager
    {
        foreach ($this->pool as $key => $pool){
            /**@var AbstractPool $pool*/
            $pool->destroyPool();
            unset($this->pool[$key]);
        }
        return $this;
    }
}
