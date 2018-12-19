<?php

namespace AppBundle\Command;

use AppBundle\Service\PrettyOutputService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Service\TableColumnService;

class ListCommand extends ContainerAwareCommand
{
    const TABLES_PARAMETER = 'tables';

    public function configure()
    {
        $this
            ->setName('rips:list')
            ->setDescription('List entries of a table')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Set table')
            ->addOption('max-chars', 'M', InputOption::VALUE_REQUIRED, 'Set max. chars per column', 40)
            ->addOption('parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters')
            ->addArgument('arguments', InputArgument::IS_ARRAY)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    public function interact(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('table')) {
            $tableQuestion = new ChoiceQuestion('Please select a table', $this->getAvailableTables());
            $input->setOption('table', $helper->ask($input, $output, $tableQuestion));
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        /** @var string $table */
        $table = (string)$input->getOption('table');

        /** @var TableColumnService $tableColumnService */
        $tableColumnService = $this->getContainer()->get(TableColumnService::class);

        $availableColumns = $tableColumnService->getColumns($table);
        $columnDetails = $tableColumnService->getColumnDetails($table);
        $serviceDetails = $tableColumnService->getServiceDetails($table);

        if (!isset($serviceDetails['list'])) {
            $output->writeln('<error>Failure:</error> This table does not support the list operation');
            return 1;
        }

        // Dynamically get all arguments.
        $arguments = $input->getArgument('arguments');
        $filteredArguments = [];

        if (isset($serviceDetails['list']['arguments'])) {
            foreach ($serviceDetails['list']['arguments'] as $argument => $details) {
                if (count($arguments) > 0) {
                    $filteredArguments[] = array_shift($arguments);
                } elseif (isset($details['required']) && $details['required']) {
                    do {
                        $argumentQuestion = new Question('Please enter a value for ' . $argument . ': ');
                        $argumentValue = $helper->ask($input, $output, $argumentQuestion);
                    } while (!$argumentValue);

                    $filteredArguments[] = $argumentValue;
                } else {
                    $argumentQuestion = new Question('You may enter a value for ' . $argument . ' (optional): ');
                    $argumentValue = $helper->ask($input, $output, $argumentQuestion);

                    if ($argumentValue) {
                        $filteredArguments[] = $argumentValue;
                    } else {
                        $filteredArguments[] = null;
                    }
                }
            }
        }

        // Add (optional) query parameters.
        $queryParams = [];
        foreach ($input->getOption('parameter') as $parameter) {
            $parameterSplit = explode('=', $parameter, 2);

            if (isset($queryParams[$parameterSplit[0]])) {
                $output->writeln('<error>Failure:</error> Query parameter collision of "' . $parameterSplit[0] . '"');
                return 1;
            }

            if (count($parameterSplit) === 1) {
                $queryParams[$parameterSplit[0]] = 1;
            } else {
                $queryParams[$parameterSplit[0]] = $parameterSplit[1];
            }
        }
        $filteredArguments[] = $queryParams;

        $service = $this->getContainer()->get($serviceDetails['name']);
        $response = call_user_func_array([$service, $serviceDetails['list']['methods'][0]], $filteredArguments);
        $elements = call_user_func([$response, $serviceDetails['list']['methods'][1]]);

        /** @var PrettyOutputService $prettyOutputService */
        $prettyOutputService = $this->getContainer()->get(PrettyOutputService::class);
        $maxChars = $input->getOption('max-chars');

        // Build the output table row by row.
        $outputTable = new Table($output);
        $outputTable->setHeaders($availableColumns);

        foreach ($elements as $element) {
            $row = [];

            foreach ($columnDetails as $column => $details) {
                if (in_array($column, $availableColumns)) {
                    $key = array_search($column, $availableColumns);

                    // Iterate through all methods until we have the value or a method returns null.
                    $currentValue = $element;
                    foreach ($details['methods'] as $method) {
                        if (is_null($currentValue)) {
                            break;
                        }

                        $currentValue = call_user_func([$currentValue, $method]);
                    }

                    $row[$key] = $prettyOutputService->toString($currentValue);
                    $row[$key] = $prettyOutputService->shortenString($row[$key], $maxChars);
                }
            }

            ksort($row);
            $outputTable->addRows([$row]);
        }
        $outputTable->render();

        return 0;
    }

    /**
     * @return array
     */
    private function getTables()
    {
        return $this->getContainer()->getParameter(self::TABLES_PARAMETER);
    }

    /**
     * @return string[]
     */
    private function getAvailableTables()
    {
        $tables = $this->getTables();

        $availableTables = [];
        foreach ($tables as $tableName => $tableDetails) {
            if (isset($tableDetails['service']['list'])) {
                $availableTables[] = $tableName;
            }
        }
        return $availableTables;
    }
}
