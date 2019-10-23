<?php


namespace EasySwoole\Redis;

/**
 * tcp交互客户端
 */
class Client
{
    /**
     * @var \Swoole\Coroutine\Client
     */
    protected $client;
    protected $host;
    protected $port;
    
    /**
     * 构造函数
     */
    function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }
    
    /**
     * 建立连接
     */
    public function connect(float $timeout = 3.0): bool
    {
        if ($this->client == null) {
            $this->client = new \Swoole\Coroutine\Client(SWOOLE_TCP);
            $this->client->set([
                'open_eof_check' => true,
                'package_eof'    => "\r\n",
            ]);
        }

        return $this->client->connect($this->host, $this->port, $timeout);
    }
    
    /**
     * 向redis服务器发送数据
     */
    public function send(string $data): bool
    {   
        //判断发送成功的数据长度是否等于原数据长度
        return strlen($data) === $this->client->send($data);
    }
    
    /**
     * 向redis发送命令
     * @param array $commandList
     * @return bool
     */
    public function sendCommand(array $commandList): bool
    {
        $argNum = count($commandList);
        //参数个数
        $str = "*{$argNum}\r\n";
        //拼接参数
        foreach ($commandList as $value) {
            $len = strlen($value);
            $str = $str . '$' . "{$len}\r\n{$value}\r\n";
        }
        return $this->send($str);
    }
    
    /**
     * 从redis服务器接收数据，生成Response实例
     */
    function recv(float $timeout = 3.0): ?Response
    {
        /*
         *
            用单行回复，回复的第一个字节将是“+”
            错误消息，回复的第一个字节将是“-”
            整型数字，回复的第一个字节将是“:”
            批量回复，回复的第一个字节将是“$”
            多个批量回复，回复的第一个字节将是“*”
         */
        $result = new Response();
        $str = $this->client->recv($timeout);
        if (empty($str)) {
            $result->setStatus($result::STATUS_TIMEOUT);
            $result->setMsg($this->client->errMsg);
            return $result;
        }
        /**
         * 去除每行结尾的的\r\n
         */
        $str = substr($str, 0, -2);
        //获取回复类型
        $op = substr($str, 0, 1);
        $result = $this->opHandel($op, $str, $timeout);
        return $result;
    }

    /**
     * 字符串处理方法
     * 根据不同的回复类型，进行不同的处理
     * opHandel
     * @param $op
     * @param $value
     * @param $timeout
     * @return Response
     * @author Tioncico
     * Time: 11:52
     */
    protected function opHandel($op, $value, float $timeout)
    {
        $result = new Response();
        switch ($op) {
            case '+':
                {
                    $result = $this->successHandel($value);
                    break;
                }
            case '-':
                {
                    $result = $this->errorHandel($value);
                    break;
                }
            case ':':
                {
                    $result = $this->intHandel($value);
                    break;
                }
            case '$':
                {
                    $result = $this->batchHandel($value, $timeout);
                    break;
                }
            case "*":
                {
                    $result = $this->multipleBatchHandel($value, $timeout);
                    break;
                }
        }
        return $result;
    }

    /**
     * 状态类型处理
     * successHandel
     * 
     * send：【*1\r\n$4\r\nPING\r\n】
     * resv：【+PONG\r\n】
     * @param $value
     * @return Response
     * @author Tioncico
     * Time: 11:52
     */
    protected function successHandel($value): Response
    {
        $result = new Response();
        $result->setStatus($result::STATUS_OK);
        $result->setData(substr($value, 1));
        return $result;
    }

    /**
     * 错误类型处理
     * errorHandel
     * 
     * send：【*2\r\n$3\r\nGET\r\n$5\r\ntest2\r\n】 查询不存在的key
     * resv：【-WRONGTYPE Operation against a key holding the wrong kind of value\r\n】
     * @param $value
     * @return Response
     * @author Tioncico
     * Time: 11:53
     */
    protected function errorHandel($value): Response
    {
        $result = new Response();
        //查看空格位置
        $spaceIndex = strpos($value, ' ');
        //查看换行位置
        $lineIndex = strpos($value, PHP_EOL);
        if ($lineIndex === false || $lineIndex > $spaceIndex) {
            $result->setErrorType(substr($value, 1, $spaceIndex - 1));

        } else {
            $result->setErrorType(substr($value, 1, $lineIndex - 1));
        }
        $result->setStatus($result::STATUS_ERR);
        $result->setMsg(substr($value, 1));
        return $result;
    }

    /**
     * int类型处理
     * intHandel
     * 
     * send：【*3\r\n$7\r\nPUBLISH\r\n$18\r\n__sentinel__:hello\r\n$90\r\n10.100.2.235,26379,0f56c6592785871a0ff3fdce22a219affa40b529,5,mymaster,10.100.3.106,6379,5\r\n】
     * resv：【:2\r\n】
     * @param $value
     * @return Response
     * @author Tioncico
     * Time: 11:53
     */
    protected function intHandel($value): Response
    {
        $result = new Response();
        $result->setStatus($result::STATUS_OK);
        $result->setData((int)substr($value, 1));
        return $result;
    }

    /**
     * 批量回复处理
     * batchHandel
     * 
     * send：【*2\r\n$3\r\nget\r\n$5\r\ntest1\r\n】 查询不存在的key
     * resv：【$17\r\naoooooooooooooooa\r\n】
     * @param $str
     * @param $timeout
     * @return bool|string
     * @author Tioncico
     * Time: 17:13
     */
    protected function batchHandel($str, float $timeout)
    {
        $response = new Response();
        //获取长度
        $strLen = substr($str, 1);
        //批量回复,继续读取字节
        $len = 0;
        $buff = '';
        if ($strLen == 0) {
            $this->client->recv($timeout);
            $response->setData('');
        } elseif ($strLen == -1) {
            $response->setData(null);
        } else {
            $eolLen = strlen("\r\n");
            //循环获取直到获取到指定长度数据
            while ($len < $strLen+$eolLen) {
                $strTmp = $this->client->recv($timeout);
                $len += strlen($strTmp);
                $buff .= $strTmp;
            }
            $response->setData(substr($buff, 0, -2));
        }
        $response->setStatus($response::STATUS_OK);
        return $response;
    }

    /**
     * 多条批量回复
     * multipleBatchHandel
     * 
     * send：【】 
     * resv：【*3\r\n$7\r\nmessage\r\n$18\r\n__sentinel__:hello\r\n$90\r\n10.100.3.106,26379,2f78bd5cfe6b1729007771c6a575a8cd448a1df6,5,mymaster,10.100.3.106,6379,5\r\n】
     * @param $value
     * @param $timeout
     * @return Response
     * @author Tioncico
     * Time: 14:33
     */
    protected function multipleBatchHandel($value, float $timeout)
    {
        $result = new Response();
        //获取回复的数量
        $len = substr($value, 1);
        if ($len == 0) {
            $result->setStatus($result::STATUS_OK);
            $result->setData([]);
        } elseif ($len == -1) {
            $result->setStatus($result::STATUS_OK);
            $result->setData(null);
        } else {
            $arr = [];
            //循环获取每个回复
            while ($len--) {
                //如下方法同批量回复
                $str = $this->client->recv($timeout);
                $str = substr($str, 0, -2);
                //获取单批量回复长度
                $op = substr($str, 0, 1);
                $response = $this->opHandel($op, $str, $timeout);
                if ($response->getStatus()!=$response::STATUS_OK){
                    $arr[] = false;
                }else{
                    $arr[] = $response->getData();
                }
            }
            $result->setStatus($result::STATUS_OK);
            $result->setData($arr);
        }
        return $result;
    }
    
    /**
     * 关闭连接
     */
    function close()
    {
        if ($this->client) {
            $this->client->close();
            $this->client = null;
        }
    }

    public function socketError()
    {
        return $this->client->errMsg;
    }

    public function socketErrno()
    {
        return $this->client->errCode;
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->close();;
    }

}