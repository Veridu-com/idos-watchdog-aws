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
            ->setName('health')
            ->setDescription('AWS WatchDog - Health Check')
            ->addOption(
                'logFile',
                'l',
                InputOption::VALUE_REQUIRED,
                'Path to log file'
            )
            ->addArgument(
                'ip',
                InputArgument::REQUIRED,
                'Socket listen IP'
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
        $logger  = new Monolog('Watchdog');
        $logger
            ->pushProcessor(new ProcessIdProcessor())
            ->pushProcessor(new UidProcessor())
            ->pushHandler(new StreamHandler($logFile, Monolog::DEBUG));

        $logger->debug('Initializing AWS WatchDog Health Check');

        // Socket listen IP and port
        $address = $input->getArgument('ip');
        $port    = (int) $input->getArgument('port');

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $logger->error('socket_create() failed: ' . socket_strerror(socket_last_error()));
        }

        if (socket_bind($socket, $address, $port) === false) {
            $logger->error('socket_bind() failed: ' . socket_strerror(socket_last_error($socket)));
        }

        if (socket_listen($socket) === false) {
            $logger->error('socket_listen() failed: ' . socket_strerror(socket_last_error($socket)));
        }

        while (true) {
            $socketMessage = socket_accept($socket);
            if ($socketMessage === false) {
                $logger->error('socket_accept() failed: ' . socket_strerror(socket_last_error($socket)));
                continue;
            }

            $logger->info('Accepted socket connection');

            while (true) {
                $buf = @socket_read($socketMessage, 2048, PHP_NORMAL_READ);
                if ($buf === false) {
                    break;
                }
            };

            $logger->info('Socket connection closed');
            socket_close($socketMessage);
        };

        socket_close($socket);
    }
}
