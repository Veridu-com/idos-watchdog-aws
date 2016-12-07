<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */

declare(strict_types = 1);

namespace Cli\Command;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\UidProcessor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command definition for WatchDog Daemon.
 */
class Elb extends Command {
    /**
     * ELB List.
     *
     * @var array
     */
    private $elbList = [];
    /**
     * IP Address List.
     *
     * @var array
     */
    private $ipList = [];

    private function elbDescribe(string $name) : string {
        $return = exec(
            sprintf(
                'aws elb describe-load-balancers --load-balancer-name %s --output text --region us-east-1 | grep \'amazonaws.com\' | awk \'{print $4}\'',
                $name
            )
        );
        if (empty($return)) {
            return '';
        }

        return $return;
    }

    private function elbEnvironment(string $name) : string {
        $return = exec(
            sprintf(
                'aws elb describe-tags --load-balancer-name %s --output text --region us-east-1 | grep ENVIRONMENT_EB | awk \'{print $3}\'',
                $name
            )
        );
        if (empty($return)) {
            return '';
        }

        return $return;
    }

    private function restartDaemons() {
        exec('supervisorctl restart daemon:*');
    }

    /**
     * Command Configuration.
     *
     * @return void
     */
    protected function configure() {
        $this
            ->setName('elb:check')
            ->setDescription('AWS WatchDog - Check for ELB Changes')
            ->addOption(
                'logFile',
                'l',
                InputOption::VALUE_REQUIRED,
                'Path to log file'
            )
            ->addArgument(
                'currentEnvironment',
                InputArgument::REQUIRED,
                'Current environment type'
            )
            ->addArgument(
                'currentElbFqdn',
                InputArgument::REQUIRED,
                'Current ELB FQDN'
            )
            ->addArgument(
                'elbList',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'ELB name list (separate values by space)'
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
        $logger = new Monolog('Manager');
        $logger->pushHandler(new StreamHandler($logFile, Monolog::DEBUG));

        $logger->debug('Initializing AWS ELB Check');

        // Current Environment Type (stage / prod)
        $currentEnvironment = $input->getArgument('currentEnvironment');

        // Current ELB FQDN
        $currentElbFqdn = $input->getArgument('currentElbFqdn');
        $ipAddr = gethostbynamel($currentElbFqdn);

        $logger->info('Connected Host', ['fqdn' => $currentElbFqdn, 'ipaddr' => $ipAddr]);

        while (true) {
            $logger->debug('Starting check loop');
            foreach ($input->getArgument('elbList') as $index => $elb) {
                $logger->debug('Checking ELB', ['index' => $index]);

                if (! isset($this->elbList[$index])) {
                    $logger->debug('Retrieving ELB details');
                    $describe = $this->elbDescribe($elb);
                    if (empty($describe)) {
                        $logger->error('Failed to retrieve ELB details');
                        continue;
                    }

                    $this->elbList[$index] = $describe;
                }

                if ((isset($this->elbList[$index])) && (! isset($this->ipList[$index]))) {
                    $logger->debug('Resolving ELB IP address');
                    $this->ipList[$index] = gethostbynamel($this->elbList[$index]);
                }

                $logger->info(
                    'ELB',
                    [
                        'index' => $index,
                        'elb' => $elb,
                        'fqdn' => $this->elbList[$index],
                        'ipaddr' => $this->ipList[$index]
                    ]
                );

                if ((! empty($this->ipList[$index])) && (! empty(array_intersect($ipAddr, $this->ipList[$index])))) {
                    $logger->info('ELB match', ['index' => $index, 'elb' => $elb]);
                    $environment = $this->elbEnvironment($elb);
                    if (empty($environment)) {
                        $logger->error('Could not retrieve ELB environment');
                        sleep(10);
                        continue 2;
                    }

                    if ($currentEnvironment !== $environment) {
                        $logger->warning(
                            'Environments do not match',
                            [
                                'curr' => $currentEnvironment,
                                'elb' => $environment
                            ]
                        );
                        sleep(30);
                        $logger->info('Restarting daemons');
                        $this->restartDaemons();
                        sleep(30);
                        $ipAddr = gethostbynamel($currentElbFqdn);
                        $logger->info('Updated Host', ['fqdn' => $currentElbFqdn, 'ipaddr' => $ipAddr]);
                    }

                    sleep(30);
                    continue 2;
                }
            }

            $logger->alert('Could not match ELB hosts');
            sleep(30);
        }
    }
}
