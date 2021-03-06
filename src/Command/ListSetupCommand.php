<?php

namespace App\Command;

use App\Service\TableColumnService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ListSetupCommand extends Command
{
    /** @var ContainerInterface */
    private $container;

    /** @var TableColumnService */
    private $tableService;

    /**
     * ListSetupCommand constructor.
     * @param ContainerInterface $container
     * @param TableColumnService $tableService
     */
    public function __construct(
        ContainerInterface $container,
        TableColumnService $tableService
    ) {
        $this->container = $container;
        $this->tableService = $tableService;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:list:setup')
            ->setDescription('Setup presentation of a table')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Set table')
            ->addOption('remove', 'r', InputOption::VALUE_NONE, 'Restore default columns')
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
        $helper = $this->getHelper('question');
        $allTables = $this->container->getParameter('tables');
        $availableTables = [];

        foreach ($allTables as $tableName => $tableDetails) {
            if (isset($tableDetails['service']['list'])) {
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

        // First check if the user just wants to restore the defaults.
        if ($input->getOption('remove')) {
            $this->tableService->removeColumns($table);
            $output->writeln('<info>Success:</info> Removed table "' . $table . '" from config');
            return 0;
        }

        $columnDetails = $this->tableService->getColumnDetails($table);

        // Print the available columns.
        $columnTable = new Table($output);
        $columnTable->setHeaders(['possible columns']);
        foreach (array_keys($columnDetails) as $column) {
            $columnTable->addRow([$column]);
        }
        $columnTable->render();

        // Verify if user really wants to modify the default columns.
        $configureQuestion = new ConfirmationQuestion('Do you want to configure the table now? (y/n) ', false);
        $configureConfirmation = $helper->ask($input, $output, $configureQuestion);
        if (!$configureConfirmation) {
            return 0;
        }

        // Get, clean, and verify the user given columns.
        $columnQuestion = new Question('Please enter the columns you want to select (separated by comma): ');
        $userColumns = explode(',', $helper->ask($input, $output, $columnQuestion));

        foreach ($userColumns as &$userColumn) {
            $userColumn = strtolower(trim($userColumn));

            if (!isset($columnDetails[$userColumn])) {
                $output->writeln('<error>Failure:</error> Column "' . $userColumn . '" not found');
                return 1;
            }
        }

        $userColumns = array_unique($userColumns);

        // Store columns in config.
        $this->tableService->storeColumns($table, $userColumns);

        return 0;
    }
}
