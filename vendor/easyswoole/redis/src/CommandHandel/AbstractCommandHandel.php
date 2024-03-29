<?php
/**
 * Created by PhpStorm.
 * User: tioncico
 * Date: 19-10-3
 * Time: 下午6:19
 */

namespace EasySwoole\Redis\CommandHandel;


use EasySwoole\Redis\Config\RedisConfig;
use EasySwoole\Redis\Pipe;
use EasySwoole\Redis\Redis;
use EasySwoole\Redis\RedisTransaction;
use EasySwoole\Redis\Response;

Abstract class AbstractCommandHandel
{
    protected $commandName;
    protected $redis;

    function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }
    
    /**
     * 获取命令参数
     * @param type $data
     * @return type
     */
    function getCommand(...$data)
    {
        $commandData = $this->handelCommandData(...$data);
        //开启了管道
        if ($this->redis->getPipe() instanceof Pipe && $this->redis->getPipe()->isStartPipe() == true) {
            //将命令添加到管道数组
            $this->redis->getPipe()->addCommand([$this->commandName, $commandData]);
            //事务命令忽略
            if (!in_array(strtolower($this->commandName), Pipe::IGNORE_COMMAND)) {
                return ['PIPE'];
            }
        }
        return $commandData;
    }
    
    /**
     * 获取服务器返回数据
     * @param Response $recv
     * @return boolean|string
     */
    function getData(Response $recv)
    {
        //开启了事务
        if ($this->redis->getTransaction() instanceof RedisTransaction && $this->redis->getTransaction()->isTransaction() == true) {
            $this->redis->getTransaction()->addCommand($this->commandName);
            //事务命令忽略
            if (!in_array(strtolower($this->commandName), RedisTransaction::IGNORE_COMMAND)) {
                return 'QUEUED';
            }
        }

        //开启了管道
        if ($this->redis->getPipe() instanceof Pipe && $this->redis->getPipe()->isStartPipe() == true) {
            //事务命令忽略
            if (!in_array(strtolower($this->commandName), Pipe::IGNORE_COMMAND)) {
                return 'PIPE';
            }
        }

        if ($recv->getStatus() != $recv::STATUS_OK) {
            $this->redis->setErrorType($recv->getErrorType());
            $this->redis->setErrorMsg($recv->getMsg());
            return false;
        }
        return $this->handelRecv($recv);
    }

    abstract function handelCommandData(...$data);

    abstract function handelRecv(Response $recv);

    /**
     * @return mixed
     */
    public function getCommandName()
    {
        return $this->commandName;
    }
    
    /**
     * 序列化值
     * @param type $val
     * @return type
     */
    protected function serialize($val)
    {
        switch ($this->redis->getConfig()->getSerialize()) {
            case RedisConfig::SERIALIZE_PHP:
                {
                    return serialize($val);
                    break;
                }

            case RedisConfig::SERIALIZE_JSON:
                {
                    return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                }
            default:
            case RedisConfig::SERIALIZE_NONE:
                {
                    return $val;
                    break;
                }
        }
    }
    
    /**
     * 反序列化值
     * @param type $val
     * @return type
     */
    protected function unSerialize($val)
    {
        switch ($this->redis->getConfig()->getSerialize()) {
            case RedisConfig::SERIALIZE_PHP:
                {
                    return unserialize($val);
                    break;
                }

            case RedisConfig::SERIALIZE_JSON:
                {
                    return json_decode($val, true);
                    break;
                }
            default:
            case RedisConfig::SERIALIZE_NONE:
                {
                    return $val;
                    break;
                }
        }
    }

}