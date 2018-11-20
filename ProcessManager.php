<?php
/**
 * Created by PhpStorm.
 * User: ema
 * Date: 19.11.2018
 * Time: 16:11
 */

namespace mikek8\daemon;


use yii\base\Exception;
use yii\helpers\FileHelper;

class ProcessManager
{
    const ON = 'on';
    const OFF = 'off';
    const WAIT = 'wait';

    protected $defaultDir = "@runtime/daemons";
    protected $daemon_name = "";
    protected $watcher_file = "";
    protected $data_file = "";
    protected $data = "";


    public function run(){

    }
    public function stop(){

    }
    public function pause(){

    }
    public function play(){

    }
    /**
     * PidManager constructor.
     * @param $daemon_name
     * @throws Exception
     */
    public function __construct($daemon_name)
    {
        $this->daemon_name = $daemon_name;
        if (!$this->setFile()) {
            throw new Exception("Cannot create file '{$this->watcher_file}' or '{$this->data_file}'");
        }
    }

    /**
     * @param string $file
     * @param string $dir
     * @return bool
     * @throws Exception
     */
    public function setFile($file = null, $dir = null)
    {
        $file = $file ?? $this->daemon_name;
        $dir = $dir ?? $this->defaultDir;
        $this->watcher_file = $dir . \DIRECTORY_SEPARATOR . $file . '.pid';
        $this->data_file = $dir . \DIRECTORY_SEPARATOR . $file . '.json';
        return FileHelper::createDirectory($dir, 0755, true) &&
            (\is_file($this->watcher_file) || \file_put_contents($this->watcher_file, null) !== false) &&
            (\is_file($this->data_file) || \file_put_contents($this->data_file, '{}'));
    }

    public function newProocess(callable $input_method)
    {

    }

    public function getStatus($group = null){
        return [];
    }

    /*
        public function saveAs($file, $dir = null)
        {
            $this->setFile($file, $dir);
            $this->save();
            return $this;
        }
    */
    public function save()
    {
        return (bool)file_put_contents($this->file, \json_encode($this->data));
    }

    public function load()
    {
        return $this->data ||
            (file_exists($this->file) && $this->data = \json_decode(file_get_contents($this->file), true));
    }

    public function checkGroup($group)
    {
        return $this->load() && isset($this->data[$group]);
    }

    public function get($group)
    {
        return $this->checkGroup($group) ? $this->data[$group] : false;
    }

    public function kill()
    {
        return $this->data && posix_kill($this->pid, SIGTERM);
    }

    public function delete()
    {
        return !file_exists($this->file) ||
            (file_exists($this->file) && unlink($this->file));
    }
}