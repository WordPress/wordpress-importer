<?php

namespace WordPress\HttpClient\Tests;

use Symfony\Component\Process\Process;

trait WithServerTrait {

    protected function withServer( callable $callback, $scenario = 'default', $host = '127.0.0.1', $port = 8950 ) {
        $serverRoot = __DIR__ . '/test-server';
        $server     = new Process( [
            'php',
            "$serverRoot/run.php",
            $host,
            $port,
            $scenario,
        ], $serverRoot );
        $server->start();
        try {
            $attempts = 0;
            while ( $server->isRunning() ) {
                $output = $server->getIncrementalOutput();
                if ( strncmp( $output, 'Server started on http://', strlen( 'Server started on http://' ) ) === 0 ) {
                    break;
                }
                usleep( 40000 );
                if ( ++ $attempts > 20 ) {
                    $this->fail( 'Server did not start' );
                }
            }
            $callback( "http://{$host}:{$port}" );
        } finally {
            $server->stop( 0 );
        }
    }

}
