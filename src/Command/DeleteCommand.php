<?php

namespace App\Command;

use App\Service\PrettyOutputService;
use App\Service\RequestService;
use App\Service\TableColumnService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DeleteCommand extends Command
{
    const TABLES_PARAMETER = 'tables';

    /** @var ContainerInterface */
    private $container;

    /** @var TableColumnService */
    private $tableService;

    /** @var RequestService */
    private $requestService;

    /** @var PrettyOutputService */
    private $prettyOutputService;

    /**
     * DeleteCommand constructor.
     * @param ContainerInterface $container
     * @param TableColumnService $tableService
     * @param RequestService $requestService
     * @param PrettyOutputService $prettyOutputService
     */
    public function __construct(
        ContainerInterface $container,
        TableColumnService $tableService,
        RequestService $requestService,
        PrettyOutputService $prettyOutputService
    )
    {
        $this->container = $container;
        $this->tableService = $tableService;
        $this->requestService = $requestService;
        $this->prettyOutputService = $prettyOutputService;

        parent::__construct();
    }

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
     * @return int
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('table')) {
            $tableQuestion = new ChoiceQuestion('Please select a table', $this->getAvailableTables());
            $input->setOption('table', $helper->ask($input, $output, $tableQuestion));
        }

        $table = (string)$input->getOption('table');
        $availableColumns = $this->tableService->getColumns($table);
        $columnDetails = $this->tableService->getColumnDetails($table);
        $serviceDetails = $this->tableService->getServiceDetails($table);

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

        $queryParams = $this->requestService->transformParametersForQuery((array)$input->getOption('parameter'));
        $readArguments[] = $queryParams;

        $service = $this->container->get($serviceDetails['name']);
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

                        $row[$key] = $this->prettyOutputService->toString($currentValue);
                        $row[$key] = $this->prettyOutputService->shortenString($row[$key], $maxChars);
                    }
                }

                ksort($row);
                $outputTable->addRow($row);
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
        return $this->container->getParameter(self::TABLES_PARAMETER);
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
