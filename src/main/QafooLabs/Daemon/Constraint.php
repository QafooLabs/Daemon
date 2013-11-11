<?php
/**
 * This file is part of the QafooLabs Daemon Component.
 *
 * @version $Revision$
 */

namespace QafooLabs\Daemon;

/**
 * Base interface for a daemon constraint.
 *
 * Constraints can be used to ensure that all requirements for a daemon are
 * fulfilled.
 */
interface Constraint
{
    /**
     * Checks if the constraints represented by the concrete implementation is
     * fulfilled. If not this method must throw an exception with an appropriate
     * error message.
     *
     * @param \QafooLabs\Daemon\Daemon
     * @return void
     * @throws \ErrorException If the constraint represented by the concrete implementation is not fulfilled.
     */
    public function check(Daemon $daemon);
}
