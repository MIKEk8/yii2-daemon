<?php
/**
 * This file is part of The simple daemon extension for the Yii 2 framework
 *
 * The daemon worker base class.
 *
 * @author Inpassor <inpassor@yandex.com>
 * @link https://github.com/Inpassor/yii2-daemon
 *
 * All the daemon workes should extend this class.
 */

namespace mikek8\daemon;

trait Worker
{
    /**
     * @var bool If set to false, worker is disabled. This parameter take effect only if set in daemon's workersMap config.
     */
    public $active = true;

    /**
     * @var int The number of maximum processes of the daemon worker running at once.
     */
    public $maxProcesses = 1;

    /**
     * @var int The time, in seconds, the timer should delay in between executions of the daemon worker.
     */
    public $delay = 60;

    public $uid = '';
    public $daemonMethod = 'run';
}
