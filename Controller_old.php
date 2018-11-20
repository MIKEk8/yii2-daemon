<?php
/**
 * The simple daemon extension for the Yii 2 framework
 *
 * @author Inpassor <inpassor@yandex.com>
 * @link https://github.com/Inpassor/yii2-daemon
 *
 * @version 0.3.2
 */

namespace inpassor\daemon;

use yii\console\ExitCode;
use \yii\helpers\FileHelper;

class ControllerOld extends \yii\console\Controller
{
    /**
     * @var string The daemon version.
     */
    const VERSION = '1.0.0';

    /**
     * @inheritdoc
     */
    public $defaultAction = 'start';

    /**
     * @var array Workers config.
     */
    public $workersMap = [];

    /**
     * @var string PID file directory.
     */
    public $pidDir = '@runtime/daemons';

    public static $workersPids = [];

    protected static $_stop = false;
    protected static $_workersConfig = [];
    protected static $_workersData = [];

    protected $_meetRequerements = false;
    protected $_pid = false;
    protected $_pidFile = null;



    /**
     * Gets all the daemon workers.
     */
    protected function _getWorkers()
    {
        foreach ($this->workersMap as $workerUid => $workerConfig) {
            if (is_string($workerConfig)) {
                $workerConfig = [
                    'class' => $workerConfig,
                ];
            }
            if (
                !isset($workerConfig['class'])
                || (isset($workerConfig['active']) && !$workerConfig['active'])
            ) {
                continue;
            }
            if (
                !isset($workerConfig['delay'])
                || !isset($workerConfig['maxProcesses'])
            ) {
                $worker = new $workerConfig['class']();
                if (!isset($workerConfig['delay'])) {
                    $workerConfig['delay'] = $worker->delay;
                }
                if (!isset($workerConfig['maxProcesses'])) {
                    $workerConfig['maxProcesses'] = $worker->maxProcesses;
                }
                if (!isset($workerConfig['active'])) {
                    $workerConfig['active'] = $worker->active;
                }
                unset($worker);
                if (!$workerConfig['active']) {
                    continue;
                }
            }
            static::$_workersData[$workerUid] = [
                'class' => $workerConfig['class'],
                'maxProcesses' => $workerConfig['maxProcesses'],
                'delay' => $workerConfig['delay'],
                'tick' => 1,
            ];
            unset($workerConfig['class']);
            static::$_workersConfig[$workerUid] = $workerConfig;
            static::$workersPids[$workerUid] = [];
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->_meetRequerements = extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->_pidFile = $this->genFile($this->pidDir,'.pid');

        return true;
    }

    protected function genFile($dir,$postfix){
        $this->pidDir = \Yii::getAlias($dir);
        if (!file_exists($this->pidDir)) {
            FileHelper::createDirectory($this->pidDir, 0755, true);
        }
        return $this->pidDir . DIRECTORY_SEPARATOR . $this->uid . $postfix;
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return [
            'uid',
        ];
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return [
            'u' => 'uid',
        ];
    }

    /**
     * PNCTL signal handler.
     * @param $signo
     * @param $pid
     * @param $status
     */
    public static function signalHandler($signo, $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                static::$_stop = true;
                break;
            case SIGCHLD:
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                while ($pid > 0) {
                    foreach (static::$workersPids as $workerUid => $workerPids) {
                        if (($key = array_search($pid, $workerPids)) !== false) {
                            unset(static::$workersPids[$workerUid][$key]);
                        }
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                }
                break;
        }
    }

    /**
     * The daemon start command.
     * @return int
     */
    public function actionStart()
    {
        $message = 'Starting Yii 2 Daemon ' . $this->version . '... ';

        if ($this->_getPid() === false) {
            $this->_getWorkers();
            if (!static::$_workersData) {
                $message .= 'No tasks found. Stopping!';
                echo $message . PHP_EOL;
                $this->_log($message);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            if ($this->_meetRequerements) {
                pcntl_signal(SIGTERM, ['inpassor\daemon\ControllerOld', 'signalHandler']);
                pcntl_signal(SIGINT, ['inpassor\daemon\ControllerOld', 'signalHandler']);
                pcntl_signal(SIGCHLD, ['inpassor\daemon\ControllerOld', 'signalHandler']);
            }
        } else {
            $message .= 'Service is already running!';
            echo $message . PHP_EOL;
            $this->_log($message);
            return ExitCode::OK;
        }

        $this->_pid = $this->_meetRequerements ? pcntl_fork() : 0;
        if ($this->_pid == -1) {
            $message .= 'Could not start service!';
            echo $message . PHP_EOL;
            $this->_log($message);
            return ExitCode::UNSPECIFIED_ERROR;
        } elseif ($this->_pid) {
            file_put_contents($this->_pidFile, $this->_pid);
            return ExitCode::OK;
        }
        if ($this->_meetRequerements) {
            posix_setsid();
        }

        $message .= 'OK.';
        echo $message . PHP_EOL;
        $this->_log($message);

        if ($this->_meetRequerements) {
            declare(ticks=1);
        };

        $previousSec = null;

        while (!static::$_stop) {
            $currentSec = date('s');
            $tickPlus = $currentSec === $previousSec ? 0 : 1;
            if ($tickPlus) {
                foreach (static::$_workersData as $workerUid => $workerData) {
                    if ($workerData['tick'] >= $workerData['delay']) {
                        $this->clearEndProc($workerUid);
                        static::$_workersData[$workerUid]['tick'] = 0;
                        $pid = 0;
                        if ($this->_meetRequerements) {
                            if (!isset(static::$workersPids[$workerUid])) {
                                static::$workersPids[$workerUid] = [];
                            }
                            $pid = (count(static::$workersPids[$workerUid]) < $workerData['maxProcesses']) ? pcntl_fork() : -2;
                        }
                        if ($pid == -1) {
                            \Yii::Error('Could not launch worker "' . $workerUid . '"');
                        } elseif ($pid) {
                            static::$workersPids[$workerUid][] = $pid;
                        } else {
                            /** @var \inpassor\daemon\Worker $worker */
                            $worker = new $workerData['class'](array_merge(isset(static::$_workersConfig[$workerUid]) ? static::$_workersConfig[$workerUid] : [], [
                                'uid' => $workerUid,
                            ]));
                            $worker->run();
                            if ($this->_meetRequerements) {
                                return ExitCode::OK;
                            }
                        }
                    }
                    static::$_workersData[$workerUid]['tick'] += $tickPlus;
                }
            }
            usleep(500000);
            $previousSec = $currentSec;
        }
        return ExitCode::OK;
    }

    /**
     * The daemon stop command.
     * @return int
     */
    public function actionStop()
    {
        $message = 'Stopping Yii 2 Daemon ' . $this->version . '... ';
        $result = ExitCode::OK;
        if ($this->_getPid() !== false) {
            $this->_killPid();
            $message .= 'OK.';
        } else {
            $message .= 'Service is not running!';
            $result = ExitCode::UNSPECIFIED_ERROR;
        }
        echo $message . PHP_EOL;
        $this->_log($message);
        return $result;
    }

    protected function clearEndProc($procGroup){
        if($this->_meetRequerements){
            foreach ( static::$workersPids[$procGroup] as $workerPidIndex => $workerPid) {
                if (!posix_kill($workerPid, 0)) {
                    unset(static::$workersPids[$procGroup][$workerPidIndex]);
                }
            }
        }
    }

    /**
     * The daemon restart command.
     * @return int
     */
    public function actionRestart()
    {
        $this->actionStop();
        return $this->actionStart();
    }

    /**
     * The daemon status command.
     * @return int
     */
    public function actionStatus()
    {
        if ($this->_getPid()) {
            echo 'Yii 2 Daemon ' . $this->version . ' status: running.' . PHP_EOL;
            return ExitCode::OK;
        }
        echo 'Yii 2 Daemon ' . $this->version . ' status: not running!' . PHP_EOL;
        return ExitCode::UNSPECIFIED_ERROR;
    }

}
