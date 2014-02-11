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
    private $contraints = array();

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
     * This method can be used to provide additional payload data from the
     * angle processes to the forked worker processes.
     *
     * This method should be overwritten if a concrete daemon requires unique
     * payload within it's sub processes.
     *
     * @return mixed
     */
    protected function generatePayload()
    {
        return null;
    }

    /**
     * Starts a new background process for the concrete daemon implementation.
     *
     * @return void
     */
    public function start()
    {
        $this->initialize();
        $this->checkConstraints();

        if ($this->debug || in_array('--spawn', $this->arguments)) {
            $this->run();

            sleep($this->quietPeriod);
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
        foreach ($this->contraints as $constraint) {
            $constraint->check($this);
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
        $this->setDefaultQuietPeriod(1);
        $this->setDefaultScript(realpath($arguments[0]));
        $this->setDefaultRampUpTime(0);
        $this->setDefaultMaxParallel(1);
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

            $this->runAngleLoop();

            closelog();
            exit(0);
        }
    }

    private function runAngleLoop()
    {
        $parallel = $this->maxParallel;
        $forks = array();

        while (true) {

            while (count($forks) < $parallel) {

                $payload = serialize($this->generatePayload());

                $pid = pcntl_fork();

                if (0 === $pid) {
                    passthru(
                        sprintf(
                            '%s --spawn --payload %s',
                            escapeshellarg($this->script),
                            escapeshellarg($payload)
                        ),
                        $exitCode
                    );

                    exit($exitCode);
                } else {
                    $forks[$pid] = $payload;
                }
            }

            do {
                // Check if the registered jobs are still alive
                if ($pid = pcntl_wait($status)) {

                    if ($status === 0) {
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

    public function setScript($script)
    {
        $this->script = $script;
    }

    /**
     * Sets the daemon identifier used to identify a spawned process.
     *
     * @param string $daemonId
     * @return void
     * @deprecated The identifier isn't used anymore for detecting a
     *             spawned process, instead we now use the --spawn
     *             cli option.
     */
    public function setDaemonId($daemonId)
    {
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

    public function setMaxParallel($maxParallel)
    {
        $this->maxParallel = $maxParallel;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param \QafooLabs\Daemon\Constraint $constraint
     * @return void
     */
    public function addConstraint(Constraint $constraint)
    {
        $this->contraints[] = $constraint;
    }

    private function setDefaultScript($script)
    {
        if (null === $this->script) {
            $this->script = $script;
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

    public function setDefaultMaxParallel($maxParallel)
    {
        if (null === $this->maxParallel) {
            $this->maxParallel = $maxParallel;
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
