<?php

namespace AppBundle\Command;

use AppBundle\Service\PrettyOutputService;
use AppBundle\Service\RequestService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Service\TableColumnService;

class DeleteCommand extends ContainerAwareCommand
{
    const TABLES_PARAMETER = 'tables';

    public function configure()
    {
        $this
            ->setName('rips:delete')
            ->setDescription('Delete entries of a table')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Set table')
            ->addOption('max-chars', 'M', InputOption::VALUE_REQUIRED, 'Set max. chars per column', 40)
            ->addOption('parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters')
            ->addArgument('arguments', InputArgument::IS_ARRAY)
            ->addOption('list', 'L', InputOption::VALUE_NONE, 'Delete multiple elements at once')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation (DANGEROUS)')
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
            exit(1);
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

        /** @var TableColumnService $tableColumnService */
        $tableColumnService = $this->getContainer()->get(TableColumnService::class);

        $table = (string)$input->getOption('table');
        $availableColumns = $tableColumnService->getColumns($table);
        $columnDetails = $tableColumnService->getColumnDetails($table);
        $serviceDetails = $tableColumnService->getServiceDetails($table);

        if (!isset($serviceDetails['delete'])) {
            $output->writeln('<error>Failure:</error> This table does not support the delete operation');
            return 1;
        }

        // Dynamically get all arguments.
        $arguments = $input->getArgument('arguments');
        $readArguments = [];

        if ($input->getOption('list')) {
            if (!isset($serviceDetails['list'])) {
                $output->writeln('<error>Failure:</error> This table does not support list operation');
                return 1;
            }

            $argumentDetails = isset($serviceDetails['list']['arguments']) ? $serviceDetails['list']['arguments'] : [];
        } else {
            if (!isset($serviceDetails['show'])) {
                $output->writeln('<error>Failure:</error> This table does not support the show operation');
                return 1;
            }

            $argumentDetails = isset($serviceDetails['show']['arguments']) ? $serviceDetails['show']['arguments'] : [];
        }

        foreach ($argumentDetails as $argument => $details) {
            if (count($arguments) > 0) {
                $readArguments[] = array_shift($arguments);
            } elseif (isset($details['required']) && $details['required']) {
                do {
                    $argumentQuestion = new Question('Please enter a value for ' . $argument . ': ');
                    $argumentValue = $helper->ask($input, $output, $argumentQuestion);
                } while (!$argumentValue);

                $readArguments[] = $argumentValue;
            } else {
                $argumentQuestion = new Question('You may enter a value for ' . $argument . ' (optional): ');
                $argumentValue = $helper->ask($input, $output, $argumentQuestion);

                if ($argumentValue) {
                    $readArguments[] = $argumentValue;
                } else {
                    $readArguments[] = null;
                }
            }
        }

        /** @var RequestService $requestService */
        $requestService = $this->getContainer()->get(RequestService::class);
        $queryParams = $requestService->transformParametersForQuery((array)$input->getOption('parameter'));
        $readArguments[] = $queryParams;

        $service = $this->getContainer()->get($serviceDetails['name']);
        if ($input->getOption('list')) {
            $response = call_user_func_array([$service, $serviceDetails['list']['methods'][0]], $readArguments);
            $elements = call_user_func([$response, $serviceDetails['list']['methods'][1]]);
        } else {
            $response = call_user_func_array([$service, $serviceDetails['show']['methods'][0]], $readArguments);
            $elements = [call_user_func([$response, $serviceDetails['show']['methods'][1]])];
        }

        if ($input->getOption('force')) {
            $deleteConfirmation = true;
        } else {
            /** @var PrettyOutputService $prettyOutputService */
            $prettyOutputService = $this->getContainer()->get(PrettyOutputService::class);
            $maxChars = (int)$input->getOption('max-chars');

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

            $output->writeln("\n" . '<error>ALL DELETED ELEMENTS AND SUB ELEMENTS WILL BE IRREVOCABLY LOST!</error>');
            $deleteQuestion = new ConfirmationQuestion('Do you really want to delete the listed elements? (y/n) ', false);
            $deleteConfirmation = $helper->ask($input, $output, $deleteQuestion);
        }

        if (!$deleteConfirmation) {
            return 0;
        }

        foreach ($elements as $element) {
            $deleteArguments = [];
            foreach ($serviceDetails['delete']['arguments'] as $argument) {
                $currentValue = $element;
                foreach ($argument['methods'] as $method) {
                    if (is_null($currentValue)) {
                        break;
                    }

                    $currentValue = call_user_func([$currentValue, $method]);
                }
                $deleteArguments[] = $currentValue;
            }
            $lastArgument = end($deleteArguments);

            if ($input->getOption('force')) {
                $deleteConfirmation = true;
            } else {
                $deleteQuestion = new ConfirmationQuestion('Do you really want to delete element ' . $lastArgument . '? (y/n) ', false);
                $deleteConfirmation = $helper->ask($input, $output, $deleteQuestion);
            }

            if ($deleteConfirmation) {
                $output->writeln('<comment>Info:</comment> Trying to remove element ' . $lastArgument, OutputInterface::VERBOSITY_VERBOSE);

                call_user_func_array([$service, $serviceDetails['delete']['method']], $deleteArguments);

                $output->writeln('<info>Success:</info> Element ' . $lastArgument . ' was successfully deleted');
            }
        }

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
            if (isset($tableDetails['service']['delete'])) {
                $availableTables[] = $tableName;
            }
        }
        return $availableTables;
    }
}
