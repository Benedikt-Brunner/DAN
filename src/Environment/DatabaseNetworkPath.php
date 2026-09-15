<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

/**
 * How the probe reaches its database container. Every path adds its own
 * per-statement transport offset and jitter, so it is frozen into the
 * manifest like every other protocol decision and the calibration history
 * can be segmented by it. The userland-proxy state of a published port is
 * a property of the Docker engine and recorded alongside it.
 */
enum DatabaseNetworkPath: string
{
    /** Host loopback to a port Docker publishes on 127.0.0.1. */
    case PublishedPort = 'published-port';
}
