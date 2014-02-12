<?php
/**
 * This file is part of the QafooLabs Daemon Component.
 *
 * @version $Revision$
 */

namespace QafooLabs\Daemon;

/**
 * Base class for a background daemon.
 */
abstract class Daemon
{
    /**
     * This exit code from a spawned process indicates that the spawned processes
     * has done some work.
     */
    const WORK_DONE_EXIT_CODE = 42;

    /**
     * @var array
     */
    private $arguments;

    /**
     * @var float
     */
    private $quietPeriod;

    /**
     * @var float
     */
    private $rampUpTime;

    /**
     * Maximum number of parallel daemon processes.
     *
     * @var integer
     */
    private $maxParallel;

    /**
     * @var string
     */
    private $errorLog = '/dev/null';

    /**
     * @var string
     */
    private $outputLog = '/dev/null';

    /**
     * @var \QafooLabs\Daemon\Constraint[]
     */
    private $constraints = array();

    /**
     * Main process method.
     *
     * Implement this method in your concrete daemon with the logic that should
     * run in background. This method should return <b>TRUE</b> if the spawned
     * process has done some work, otherwise return <b>FALSE</b>.
     *
     * @return boolean
     */
    abstract protected function run();

    /**
     * Starts a new background process for the concrete daemon implementation.
     *
     * @return void
     */
    public function start()
    {
        $this->initialize();
        $this->checkConstraints();

        if (in_array('--spawn', $this->arguments)) {
            $this->run();
        } else {
            $this->doStart();
        }
    }

    /**
     * Tests if all constraints for the concrete daemon are fulfilled.
     *
     * @return void
     * @throws \ErrorException If one of the defined daemon constraints is not fulfilled.
     */
    private function checkConstraints()
    {
        foreach ($this->constraints as $constraint) {
            $constraint->check($this);
        }
    }

    /**
     * Initializes the daemon with default values for quiet period, ramp-up time,
     * number of parallel processes etc.
     *
     * @return void
     * @throws \ErrorException
     */
    private function initialize()
    {
        if (isset($GLOBALS['argv'])) {
            $arguments = $GLOBALS['argv'];
        } elseif (isset($_SERVER['argv'])) {
            $arguments = $_SERVER['argv'];
        } else {
            throw new \ErrorException(
                'Cannot find $argv. Perhaps daemon not started from CLI?'
            );
        }

        $this->setDefaultArguments($arguments);
        $this->setDefaultQuietPeriod(1);
        $this->setDefaultRampUpTime(0);
        $this->setDefaultMaxParallel(1);
    }

    /**
     * Starts the main daemon process.
     *
     * This method detaches the daemon process from the current shell session
     * and spawns the daemon into the background.
     *
     * @return void
     */
    private function doStart()
    {
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        } elseif (0 === $pid) {
            // Set umask
            umask(0);

            // Open syslog connection
            openlog('DaemonLog', LOG_PID | LOG_PERROR, LOG_LOCAL0);

            // Get a new session id
            $sid = posix_setsid();
            if ($sid < 0) {
                syslog(LOG_ERR, 'Could not detach session id.');
                exit(1);
            }

            // Close standard I/O descriptors
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            // Initialize new standard I/O descriptors
            $STDIN  = fopen('/dev/null', 'r'); // STDIN
            $STDOUT = fopen($this->outputLog, 'a'); // STDOUT
            $STDERR = fopen($this->errorLog, 'a'); // STDERR

            $this->waitRampUpTime();

            $this->doStartLoop();

            closelog();
            exit(0);
        }
    }

    /**
     * Starts the daemon endless loop.
     *
     * Within this method we start an endless loop which then spawns the worker
     * processes.
     *
     * @return void
     */
    private function doStartLoop()
    {
        $parallel = $this->maxParallel;
        $forks = array();

        while (true) {

            while (count($forks) < $parallel) {

                $pid = pcntl_fork();

                if (0 === $pid) {
                    $exitCode = $this->run();

                    usleep($this->quietPeriod * 1000000);

                    exit($exitCode === true ? self::WORK_DONE_EXIT_CODE : 0);
                } else {
                    $forks[$pid] = $pid;
                }
            }

            do {
                // Check if the registered jobs are still alive
                if ($pid = pcntl_wait($status)) {

                    if (self::WORK_DONE_EXIT_CODE === pcntl_wexitstatus($status)) {
                        $parallel = $this->maxParallel;
                    } else if ($parallel > 1) {
                        $parallel = $parallel - 1;
                    }
                    unset($forks[$pid]);
                }
            } while (count($forks) >= $parallel);
        }
    }

    public function setOutputLog($outputLog)
    {
        $this->outputLog = $outputLog;
    }

    public function setErrorLog($errorLog)
    {
        $this->errorLog = $errorLog;
    }

    public function setArguments(array $arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Sets the quiet period between to worker runs.
     *
     * Each worker process will wait <b>$seconds</b> after it has done it's job.
     * The quiet period will be used to reduce the busy waiting load on the
     * server.
     *
     * @param float $seconds
     * @return void
     */
    public function setQuietPeriod($seconds)
    {
        $this->quietPeriod = $seconds;
    }

    /**
     * Sets the ramp-up time for the daemon.
     *
     * When the daemon get's started it wait <b>$seconds</b> before it spawns
     * the first worker sub process.
     *
     * @param float $seconds
     * @return void
     */
    public function setRampUpTime($seconds)
    {
        $this->rampUpTime = $seconds;
    }

    /**
     * Sets the maximum number of parallel processes.
     *
     * The daemon will spawn up to <b>$maxParallel</b> child processes when
     * enough workload is present.
     *
     * @param integer $maxParallel
     * @return void
     */
    public function setMaxParallel($maxParallel)
    {
        $this->maxParallel = $maxParallel;
    }

    /**
     * Adds a additional execution constraint.
     *
     * The daemon component uses constraints to determine if a concrete daemon
     * should run on the current machine, in the current environment etc. There
     * are no default constraints and it's up to the user of this component to
     * implement custom constrains.
     *
     * @param \QafooLabs\Daemon\Constraint $constraint
     * @return void
     */
    public function addConstraint(Constraint $constraint)
    {
        $this->constraints[] = $constraint;
    }

    /**
     * Sets the default execution arguments.
     *
     * Normally the arguments will be similar <b>$_SERVER['argv']</b>. When a
     * custom daemon implementation has already configured the arguments a call
     * to this method will not overwrite the existing value.
     *
     * @param array $arguments
     * @return void
     */
    private function setDefaultArguments(array $arguments)
    {
        if (null === $this->arguments) {
            $this->arguments = $arguments;
        }
    }

    private function setDefaultQuietPeriod($seconds)
    {
        if (null === $this->quietPeriod) {
            $this->quietPeriod = $seconds;
        }
    }

    private function setDefaultRampUpTime($seconds)
    {
        if (null === $this->rampUpTime) {
            $this->rampUpTime = $seconds;
        }
    }

    /**
     * Sets the default value for the maximum number of parallel processes.
     *
     * When a custom daemon implementation has already configured the maximum
     * number of parallel processes this method will not overwrite the existing
     * value.
     *
     * @param integer $maxParallel
     * @return void
     */
    private function setDefaultMaxParallel($maxParallel)
    {
        if (null === $this->maxParallel) {
            $this->maxParallel = $maxParallel;
        }
    }

    /**
     * Waits the configured ramp-up time when the daemon get's started.
     *
     * @return void
     */
    private function waitRampUpTime()
    {
        if (null === $this->rampUpTime) {
            return;
        }

        usleep($this->rampUpTime * 1000000);
    }

    /**
     * @param boolean $debug
     * @return void
     * @deprecated
     */
    public function setDebug($debug)
    {
    }
}
