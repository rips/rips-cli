<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ExportScanCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:scan:export')
            ->setDescription('Export a scan')
            ->addOption('application', 'a', InputOption::VALUE_REQUIRED, 'Set application id')
            ->addOption('scan', 's', InputOption::VALUE_REQUIRED, 'Set scan id')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Set output file')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Set type of export')
            ->addOption('parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');
        $types = $this->getContainer()->getParameter('export_types');
        $availableTypes = array_keys($types);

        if (!$applicationId = $input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $applicationId = $helper->ask($input, $output, $applicationQuestion);
        }

        if (!$scanId = $input->getOption('scan')) {
            $scanQuestion = new Question('Please enter a scan id: ');
            $scanId = $helper->ask($input, $output, $scanQuestion);
        }

        if (!$type = $input->getOption('type')) {
            $typeQuestion = new ChoiceQuestion('Please select a type', $availableTypes);
            $type = $helper->ask($input, $output, $typeQuestion);
        }

        if (!in_array($type, $availableTypes)) {
            $output->writeln('<error>Failure:</error> Type "' . $type . '" not found (' . implode(', ', $availableTypes) . ')');
            return 1;
        }

        $selectedType = $types[$type];

        if (!$file = $input->getOption('file')) {
            $fileQuestion = new Question('You may enter a file name (optional): ');
            $file = $helper->ask($input, $output, $fileQuestion);
        }

        // Create file name in case --no-interaction is used.
        if (!$file) {
            $file = $applicationId . '_' . $scanId . '.' . $selectedType['extension'];
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

        // Add default query parameters. Do not override custom parameters.
        if (isset($selectedType['default_parameters']) && is_array($selectedType['default_parameters'])) {
            $queryParams = array_replace($selectedType['default_parameters'], $queryParams);
        }

        $output->writeln('<comment>Info:</comment> Scan is being exported to "' . $file . '"', OutputInterface::VERBOSITY_VERBOSE);

        /** @var \RIPS\ConnectorBundle\Services\Application\Scan\ExportService $exportService */
        $exportService = $this->getContainer()->get('rips_connector.application.scan.exports');
        call_user_func([$exportService, $selectedType['method']], $applicationId, $scanId, $file, $queryParams);

        $output->writeln('<info>Success:</info> Scan was successfully exported to "' . $file . '"');

        return 0;
    }
}
