<?php

namespace AppBundle\Command;

use AppBundle\Service\PrettyOutputService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Service\TableColumnService;

class ListIssuesCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:issues:list')
            ->setDescription('List issues of all scans')
            ->addOption('max-chars', 'M', InputOption::VALUE_REQUIRED, 'Set max. chars per column', 40)
            ->addOption('issue-parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters for issues')
            ->addOption('scan-parameter', 'P', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters for scans')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        // Read out the columns of the table from the config.
        /** @var TableColumnService $tableColumnService */
        $tableColumnService = $this->getContainer()->get(TableColumnService::class);

        $availableColumns = $tableColumnService->getColumns('issues');
        $columnDetails = $tableColumnService->getColumnDetails('issues');

        /** @var PrettyOutputService $prettyOutputService */
        $prettyOutputService = $this->getContainer()->get(PrettyOutputService::class);
        $maxChars = $input->getOption('max-chars');

        // Build the output table row by row.
        $table = new Table($output);
        $table->setHeaders($availableColumns);

        // Add (optional) query parameters for scans.
        $scanQueryParams = [];
        foreach ($input->getOption('scan-parameter') as $parameter) {
            $parameterSplit = explode('=', $parameter, 2);

            if (isset($scanQueryParams[$parameterSplit[0]])) {
                $output->writeln('<error>Failure:</error> Query parameter collision of "' . $parameterSplit[0] . '"');
                return 1;
            }

            if (count($parameterSplit) === 1) {
                $scanQueryParams[$parameterSplit[0]] = 1;
            } else {
                $scanQueryParams[$parameterSplit[0]] = $parameterSplit[1];
            }
        }

        /** @var \RIPS\ConnectorBundle\Services\Application\ScanService $scanService */
        $scanService = $this->getContainer()->get('rips_connector.application.scans');
        $scans = $scanService->getAll(null, $scanQueryParams);

        // Add (optional) query parameters for issues.
        $issueQueryParams = [];
        foreach ($input->getOption('issue-parameter') as $parameter) {
            $parameterSplit = explode('=', $parameter, 2);

            if (isset($issueQueryParams[$parameterSplit[0]])) {
                $output->writeln('<error>Failure:</error> Query parameter collision of "' . $parameterSplit[0] . '"');
                return 1;
            }

            if (count($parameterSplit) === 1) {
                $issueQueryParams[$parameterSplit[0]] = 1;
            } else {
                $issueQueryParams[$parameterSplit[0]] = $parameterSplit[1];
            }
        }

        foreach ($scans as $scan) {
            $output->writeln(
                '<comment>Info:</comment> Searching in application "' .
                $scan->getApplication()->getName() . '" (' . $scan->getApplication()->getId() . ') ' .
                'scan "' . $scan->getVersion() . '" (' . $scan->getId() . ')',
                OutputInterface::VERBOSITY_VERBOSE
            );

            /** @var \RIPS\ConnectorBundle\Services\Application\Scan\IssueService $issueService */
            $issueService = $this->getContainer()->get('rips_connector.application.scan.issues');
            $issues = $issueService->getAll($scan->getApplication()->getId(), $scan->getId(), $issueQueryParams);

            foreach ($issues as $issue) {
                $row = [];

                foreach ($columnDetails as $column => $details) {
                    if (in_array($column, $availableColumns)) {
                        $key = array_search($column, $availableColumns);

                        // Iterate through all methods until we have the value or a method returns null.
                        $currentValue = $issue;
                        foreach ($details['methods'] as $method) {
                            if (is_null($currentValue)) {
                                break;
                            }

                            $currentValue = call_user_func([$currentValue, $method]);
                        }

                        // Get value as string. Format it if necessary.
                        if ($currentValue instanceof \DateTime) {
                            $row[$key] = $currentValue->format(DATE_RFC822);
                        } elseif (is_bool($currentValue)) {
                            $row[$key] = $currentValue ? 'true' : 'false';
                        } elseif (is_array($currentValue)) {
                            $row[$key] = implode(', ', $currentValue);
                        } else {
                            $row[$key] = (string)$currentValue;
                        }

                        $row[$key] = $prettyOutputService->shortenString($row[$key], $maxChars);
                    }
                }

                ksort($row);
                $table->addRows([$row]);
            }
        }

        $table->render();

        return 0;
    }
}
