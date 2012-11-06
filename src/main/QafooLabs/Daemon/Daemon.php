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
     * @var boolean
     */
    private $debug = false;

    /**
     * @var string
     */
    private $script;

    /**
     * @var string
     */
    private $daemonId;

    /**
     * @var array
     */
    private $arguments;

    /**
     * @var integer
     */
    private $quietPeriod;

    /**
     * @var integer
     */
    private $rampUpTime;

    /**
     * @var string
     */
    private $errorLog = '/dev/null';

    /**
     * @var string
     */
    private $outputLog = '/dev/null';

    /**
     * Main process method.
     *
     * Implement this method in your concrete daemon with the logic that should
     * run in background.
     *
     * @return void
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

        if ($this->debug || in_array($this->daemonId, $this->arguments)) {
            $this->run();

            sleep($this->quietPeriod);
        } else {
            $this->doStart();
        }
    }

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
        $this->setDefaultDaemonId(md5(get_class($this)));
        $this->setDefaultQuietPeriod(1);
        $this->setDefaultScript(realpath($arguments[0]));
        $this->setDefaultRampUpTime(0);
    }

    /**
     *
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

            // Change working directory.
            chdir(dirname($this->script));

            // Close standard I/O descriptors
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            // Initialize new standard I/O descriptors
            $STDIN  = fopen('/dev/null', 'r'); // STDIN
            $STDOUT = fopen($this->outputLog, 'a'); // STDOUT
            $STDERR = fopen($this->errorLog, 'a'); // STDERR

            $this->waitRampUpTime();

            while (true) {
                passthru(
                    sprintf(
                        '%s %s',
                        escapeshellarg($this->script),
                        escapeshellarg($this->daemonId)
                    )
                );
            }

            closelog();
            exit(0);
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

    public function setScript($script)
    {
        $this->script = $script;
    }

    public function setDaemonId($daemonId)
    {
        $this->daemonId = $daemonId;
    }

    public function setArguments(array $arguments)
    {
        $this->arguments = $arguments;
    }

    public function setQuietPeriod($seconds)
    {
        $this->quietPeriod = $seconds;
    }

    public function setRampUpTime($seconds)
    {
        $this->rampUpTime = $seconds;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    private function setDefaultScript($script)
    {
        if (null === $this->script) {
            $this->script = $script;
        }
    }

    private function setDefaultDaemonId($daemonId)
    {
        if (null === $this->daemonId) {
            $this->daemonId = $daemonId;
        }
    }

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

    private function waitRampUpTime()
    {
        if (null === $this->rampUpTime) {
            return;
        }

        sleep($this->rampUpTime);
    }
}
