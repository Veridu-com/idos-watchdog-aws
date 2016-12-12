<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace Cli\Command;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\UidProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command definition for WatchDog Daemon health check.
 */
class Health extends Command {
    /**
     * Command Configuration.
     *
     * @return void
     */
    protected function configure() {
        $this
            ->setName('check:health')
            ->setDescription('AWS WatchDog - Health Check')
            ->addOption(
                'logFile',
                'l',
                InputOption::VALUE_REQUIRED,
                'Path to log file'
            )
            ->addOption(
                'ipAddr',
                'ip',
                InputOption::VALUE_REQUIRED,
                'IP Address to listen on (default: all interfaces)'
            )
            ->addArgument(
                'port',
                InputArgument::REQUIRED,
                'Socket listen port'
            );
    }

    /**
     * Command Execution.
     *
     * @param Symfony\Component\Console\Input\InputInterface   $input
     * @param Symfony\Component\Console\Output\OutputInterface $outpput
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $logFile = $input->getOption('logFile') ?? 'php://stdout';
        $logger  = new Monolog('Health');
        $logger
            ->pushProcessor(new ProcessIdProcessor())
            ->pushProcessor(new UidProcessor())
            ->pushHandler(new StreamHandler($logFile, Monolog::DEBUG));

        $logger->debug('Initializing AWS WatchDog Health Check');

        // Socket listen IP and port
        $ipAddr = $input->getOption('ipAddr') ?? '0.0.0.0';
        $port   = (int) $input->getArgument('port');

        $listenSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($listenSocket === false) {
            $logger->error(
                'socket_create() failed',
                [
                    'code'  => socket_last_error(),
                    'error' => socket_strerror(socket_last_error())
                ]
            );
            socket_close($listenSocket);

            return;
        }

        if (@socket_bind($listenSocket, $ipAddr, $port) === false) {
            $logger->error(
                'socket_bind() failed',
                [
                    'code'  => socket_last_error(),
                    'error' => socket_strerror(socket_last_error($listenSocket))
                ]
            );
            socket_close($listenSocket);

            return;
        }

        if (@socket_listen($listenSocket) === false) {
            $logger->error(
                'socket_listen() failed',
                [
                    'code'  => socket_last_error(),
                    'error' => socket_strerror(socket_last_error($listenSocket))
                ]
            );
            socket_close($listenSocket);

            return;
        }

        $logger->info('Waiting for connections', ['ipAddr' => $ipAddr, 'port' => $port]);

        while (true) {
            $remoteSocket = @socket_accept($listenSocket);
            if ($remoteSocket === false) {
                $logger->error(
                    'socket_accept() failed',
                    [
                        'code'  => socket_last_error(),
                        'error' => socket_strerror(socket_last_error($listenSocket))
                    ]
                );
                continue;
            }

            socket_getpeername($remoteSocket, $remoteIpAddr, $remotePort);

            $logger->info('Accepted socket connection', ['ipAddr' => $remoteIpAddr, 'port' => $remotePort]);

            while (true) {
                $buffer = @socket_read($remoteSocket, 4096, PHP_BINARY_READ);
                if (empty($buffer)) {
                    break;
                }
            }

            $logger->info('Socket connection closed');
            socket_close($remoteSocket);
        }

        socket_close($listenSocket);
    }
}
