<?php

namespace xuntee\cron\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use \think\Config;
use think\Db;

class MySql extends Command
{
    protected $config;

    protected $sql;

    protected function configure()
    {
        $this->setName('cron:install')->setDescription('Crontab Data table initialization');
        $this->config = Config::get('cron');
    }

    protected function execute(Input $input, Output $output)
    {
        $isTable = Db::execute("SHOW TABLES LIKE '{$this->config['table']}'");
        if( $isTable ){
            $output->comment("The data table already exists");
        }else{
            $status = Db::execute($this->sql);
            if($status!==false){
                $output->info("Data table initialization succeeded!");
            }else{
                $output->error("Data table initialization failed, please check!");
            }
        }
    }
    protected function initialize(Input $input, Output $output){
         $this->sql = <<<sql
CREATE TABLE `{$this->config['table']}` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `count` int(11) NOT NULL DEFAULT '0' COMMENT '执行次数',
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '任务状态',
  `title` char(50) DEFAULT NULL COMMENT '任务名称',
  `expression` char(200) NOT NULL DEFAULT '* * * * *' COMMENT '任务周期',
  `task` varchar(500) DEFAULT NULL COMMENT '任务命令',
  `data` longtext COMMENT '附件参数',
  `status_desc` varchar(1000) DEFAULT NULL COMMENT '上次执行结果',
  `last_time` datetime DEFAULT NULL COMMENT '最后执行时间',
  `next_time` datetime DEFAULT NULL COMMENT '下次执行时间',
  `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
  `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
sql;
    }

    public function add_cron($title, $task, $data = [], $expression=null)
    {
        return Db::table($this->config['table'])->insert([
            'title'     => $title,
            'task'      => $task,
            'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
            'expression'   => empty($expression)?:$expression,
            'create_time'       => time(),
            'update_time'       => time(),
        ]);
    }
}
