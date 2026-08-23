<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use RuntimeException;

final class TcpPortProvider
{
    public static function getPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Could not allocate a free TCP port.');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($name === false) {
            throw new RuntimeException('Could not determine the allocated TCP port.');
        }

        $separatorPosition = strrpos($name, ':');
        if ($separatorPosition === false) {
            throw new RuntimeException(sprintf('Could not parse the allocated TCP port from "%s".', $name));
        }

        return (int) substr($name, $separatorPosition + 1);
    }
}
