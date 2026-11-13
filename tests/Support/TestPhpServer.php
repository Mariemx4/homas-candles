<?php

declare(strict_types=1);

/**
 * A temporary `php -S` web server for HTTP suites, bound to a free loopback port.
 *
 * Typical use:
 *
 *     $address = TestPhpServer::reserveAddress();
 *     $server = TestPhpServer::start($address, $sessionDirectory, $logFile, [
 *         'PGOPTIONS' => '-c search_path=' . $schema,   // point the app at the throwaway schema
 *         'APP_BASE_URL' => '/',
 *         'HOMAS_VERIFICATION_DATABASE' => null,         // null removes a variable
 *     ]);
 *     try { ... requests to 'http://' . $address . '/...' ... }
 *     finally { $server?->stop(); }
 *
 * The server inherits this process's environment plus the given changes, so a
 * suite controls exactly which database schema and configuration it serves.
 */
final class TestPhpServer
{
    /** @param resource $process */
    private function __construct(private $process)
    {
    }

    /** Returns a free "127.0.0.1:port" address (reserve it first when the environment must mention it). */
    public static function reserveAddress(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        if ($listener === false) {
            throw new RuntimeException('Could not reserve an HTTP test port.');
        }
        $address = stream_socket_get_name($listener, false);
        fclose($listener);

        return $address;
    }

    /**
     * Starts the server on PUBLIC_PATH and waits (up to about 8 seconds) until it accepts connections.
     *
     * @param array<string, string|null> $environment variables to set; a null value removes the variable
     * @param array<string, string>      $ini         extra php.ini settings, e.g. ['post_max_size' => '8M']
     * @param string|null                $router      optional router script passed to `php -S`
     */
    public static function start(
        string $address,
        string $sessionDirectory,
        string $logFile,
        array $environment,
        array $ini = [],
        ?string $router = null
    ): self {
        $command = [PHP_BINARY, '-d', 'session.save_path=' . $sessionDirectory];
        foreach ($ini as $name => $value) {
            array_push($command, '-d', $name . '=' . $value);
        }
        array_push($command, '-S', $address, '-t', PUBLIC_PATH);
        if ($router !== null) {
            $command[] = $router;
        }

        $variables = getenv();
        foreach ($environment as $name => $value) {
            if ($value === null) {
                unset($variables[$name]);
            } else {
                $variables[$name] = $value;
            }
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            PROJECT_ROOT,
            $variables
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the test PHP server.');
        }
        fclose($pipes[0]);
        $server = new self($process);

        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $probe = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return $server;
            }
            usleep(100000);
        }
        $server->stop();
        throw new RuntimeException('The test PHP server did not become ready.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
