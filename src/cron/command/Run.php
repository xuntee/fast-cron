<?php

namespace xuntee\cron\command;

use Carbon\Carbon;
use \think\Cache;

use \think\Config;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Db;


use xuntee\cron\Task;

class Run extends Command
{
    protected $type;

    protected $config;

    protected $startedAt;

    protected $taskData = [];

    protected function configure()
    {
        $this->startedAt = Carbon::now();
        $this->setName('cron:run')
            ->addOption('memory', null, Option::VALUE_OPTIONAL, 'The memory limit in megabytes', 128)
            ->setDescription('Running a scheduled task');
        $this->config = Config::get('cron');
        $this->type   = strtolower($this->config['type']);
    }

    public function execute(Input $input, Output $output)
    {
        //防止页面超时,实际上CLI命令行模式下 本身无超时时间
        ignore_user_abort(true);
        set_time_limit(0);
        file_put_contents("cron_log.log","-----------$this->type---------\n",FILE_APPEND);
        
        if ($this->type == 'file') {
            $tasks = $this->config['tasks'];
            if (empty($tasks)) {
                $output->comment("No tasks to execute");
                return false;
            }
        } elseif ($this->type == 'mysql' && Db::execute("SHOW TABLES LIKE '{$this->config['table']}'")) {
        file_put_contents("cron_log.log","开始\n",FILE_APPEND);
            $tasks = $this->tasksSql($this->config['cache'] ?: 60);
            if (empty($tasks)) {
        file_put_contents("cron_log.log","空\n",FILE_APPEND);

                $output->comment("No tasks to execute");
                return false;
            }

        } else {
            $output->error("Please first set config type is mysql and execute: php think cron:install");
            return false;
        }
        // file_put_contents("cron_log.log",print_r($tasks,true),FILE_APPEND);

        foreach ($tasks as $k => $vo) {
        file_put_contents("cron_log.log",print_r($vo,true),FILE_APPEND);

            $taskClass = $vo['task'];
            $expression   = empty($vo['expression']) ? false : $vo['expression'];

            $this->taskData['id'] = $k;
            if (is_subclass_of($taskClass, Task::class)) {
                /** @var Task $task */
                $task = new $taskClass($expression);
                if ($this->type == 'mysql') {
                    $task->payload = json_decode($vo['data'], true);
                } else {
                    $task->payload = empty($vo['data']) ? [] : $vo['data'];
                }
                if ($task->isDue()) {
                    if (!$task->filtersPass()) {
                        continue;
                    }

                    if ($task->onOneServer) {
                        $this->runSingleServerTask($task);
                    } else {
                        $this->runTask($task);
                    }
                    $output->writeln("<info>Task {$taskClass} run at " . $this->startedAt . "</info>");
                }
            }else{
        file_put_contents("cron_log.log",$vo['task'].'不是子类'."\n",FILE_APPEND);

            }
        }
    }

    protected function tasksSql($time = 60)
    {
        return Db::table($this->config['table'])->cache(true, $time)->where('status', 1)->order('sort', 'asc')->column(
            'title,expression,task,data',
            'id'
        );
    }

    /**
     * @param $task Task
     * @return bool
     */
    protected function serverShouldRun($task)
    {
        $key = $task->mutexName() . $this->startedAt->format('H:i');
        if (Cache::has($key)) {
            return false;
        }
        Cache::set($key, true, 60);
        return true;
    }

    protected function runSingleServerTask($task)
    {
        if ($this->serverShouldRun($task)) {
            $this->runTask($task);
        } else {
            $this->output->writeln(
                '<info>Skipping task (has already run on another server):</info> ' . get_class($task)
            );
        }
    }

    /**
     * @param $task Task
     */
    protected function runTask($task)
    {
        $task->run();
        $this->taskData['status_desc'] = $task->statusDesc;
        $this->taskData['next_time']   = $task->NextRun($this->startedAt);
        $this->taskData['last_time']   = $this->startedAt;
        $this->taskData['count']       = Db::raw('count+1');
        if ($this->type == 'mysql') {
            Db::table($this->config['table'])->update($this->taskData);
        } else {
            Cache::set('cron-' . $this->taskData['id'], $this->taskData, 0);
        }
    }
}
