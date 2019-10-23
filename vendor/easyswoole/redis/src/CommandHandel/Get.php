<?php
namespace EasySwoole\Redis\CommandHandel;

use EasySwoole\Redis\CommandConst;
use EasySwoole\Redis\Redis;
use EasySwoole\Redis\Response;

class Get extends AbstractCommandHandel
{
	public $commandName = 'Get';

        /**
         * 获取命令发送数据
         * @param type $data
         * @return type
         */
	public function handelCommandData(...$data)
	{
		$key=array_shift($data);


		        

		$command = [CommandConst::GET,$key];
		$commandData = array_merge($command,$data);
		return $commandData;
	}

        /**
         * 获取命令返回结果
         * @param Response $recv
         * @return type
         */
	public function handelRecv(Response $recv)
	{
		$data = $recv->getData();
		        return $this->unSerialize($data);
	}
}
