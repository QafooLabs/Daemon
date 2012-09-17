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
    private $handle;

    /**
     * @var array
     */
    private $argv;

    /**
     * @var integer
     */
    private $quietPeriod = 10;

    /**
     * @var string
     */
    private $errorLog = '/dev/null';

    /**
     * @var string
     */
    private $outputLog = '/dev/null';

    public function __construct(array $argv)
    {
        $this->script = realpath($argv[0]);
        $this->handle = md5(get_class($this));
        $this->argv = $argv;
    }

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
        if ($this->debug || in_array($this->handle, $this->argv)) {
            $this->run();
        } else {
            $this->doStart();
        }
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
            chdir(dirname($this->argv[0]));

            // Close standard I/O descriptors
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);

            // Initialize new standard I/O descriptors
            $STDIN  = fopen('/dev/null', 'r'); // STDIN
            $STDOUT = fopen($this->outputLog, 'a'); // STDOUT
            $STDERR = fopen($this->errorLog, 'a'); // STDERR

            while (true) {
                passthru(
                    sprintf(
                        '%s %s',
                        escapeshellarg($this->script),
                        escapeshellarg($this->handle)
                    )
                );
                sleep($this->quietPeriod);
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
}
