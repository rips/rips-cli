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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Service\TableColumnService;

class DeleteCommand extends ContainerAwareCommand
{
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

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');
        $allTables = $this->getContainer()->getParameter('tables');
        $availableTables = [];

        foreach ($allTables as $tableName => $tableDetails) {
            if (isset($tableDetails['service']['delete'])) {
                $availableTables[] = $tableName;
            }
        }

        // Get the target table from an option or as a fallback from stdin.
        if (!$table = $input->getOption('table')) {
            $tableQuestion = new ChoiceQuestion('Please select a table', $availableTables);
            $table = $helper->ask($input, $output, $tableQuestion);
        }

        if (!in_array($table, $availableTables)) {
            $output->writeln('<error>Failure:</error> Table "' . $table . '" not found');
            return 1;
        }

        /** @var TableColumnService $tableColumnService */
        $tableColumnService = $this->getContainer()->get(TableColumnService::class);

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
        $readArguments[] = $queryParams;

        $service = $this->getContainer()->get($serviceDetails['name']);
        if ($input->getOption('list')) {
            $elements = call_user_func_array([$service, $serviceDetails['list']['method']], $readArguments);
        } else {
            $elements = [call_user_func_array([$service, $serviceDetails['show']['method']], $readArguments)];
        }

        if ($input->getOption('force')) {
            $deleteConfirmation = true;
        } else {
            /** @var PrettyOutputService $prettyOutputService */
            $prettyOutputService = $this->getContainer()->get(PrettyOutputService::class);
            $maxChars = $input->getOption('max-chars');

            // Build the output table row by row.
            $table = new Table($output);
            $table->setHeaders($availableColumns);

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
            $table->render();

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
}
