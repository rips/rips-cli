<?php

namespace AppBundle\Command;

use AppBundle\Service\RequestService;
use RIPS\ConnectorBundle\Services\Application\Scan\ExportService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ExportScanCommand extends ContainerAwareCommand
{
    const EXPORT_TYPES_PARAMETER = 'export_types';

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

        if (!$input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $input->setOption('application', $helper->ask($input, $output, $applicationQuestion));
        }

        if (!$input->getOption('scan')) {
            $scanQuestion = new Question('Please enter a scan id: ');
            $input->setOption('scan', $helper->ask($input, $output, $scanQuestion));
        }

        if (!$input->getOption('type')) {
            $typeQuestion = new ChoiceQuestion('Please select a type', $this->getAvailableTypes());
            $input->setOption('type', $helper->ask($input, $output, $typeQuestion));
        }

        if (!$input->getOption('file')) {
            $fileQuestion = new Question('You may enter a file name (optional): ');
            $input->setOption('file', $helper->ask($input, $output, $fileQuestion));
        }
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var string $type */
        $type = (string)$input->getOption('type');

        /** @var array $typeData */
        $typeData = $this->getType($type);

        /** @var int $applicationId */
        $applicationId = (int)$input->getOption('application');

        /** @var int $scanId */
        $scanId = (int)$input->getOption('scan');

        /** @var string $file */
        $file = (string)$input->getOption('file');
        if (!$file) {
            // Create file name in case --no-interaction is used.
            $file = $applicationId . '_' . $scanId . '.' . $typeData['extension'];
        }

        /** @var RequestService $requestService */
        $requestService = $this->getContainer()->get(RequestService::class);
        $queryParams = $requestService->transformParametersForQuery($input->getOption('parameter'));

        $output->writeln('<comment>Info:</comment> Scan is being exported to "' . $file . '"', OutputInterface::VERBOSITY_VERBOSE);

        /** @var ExportService $exportService */
        $exportService = $this->getContainer()->get(ExportService::class);
        call_user_func([$exportService, $typeData['method']], $applicationId, $scanId, $file, $queryParams);

        $output->writeln('<info>Success:</info> Scan was successfully exported to "' . $file . '"');

        return 0;
    }

    /**
     * @param string $type
     * @return array
     */
    private function getType($type)
    {
        $types = $this->getContainer()->getParameter(self::EXPORT_TYPES_PARAMETER);

        if (!isset($types[$type])) {
            $availableTypes = $this->getAvailableTypes();
            throw new \RuntimeException('Type "' . $type . '" not found (' . implode(', ', $availableTypes) . ')');
        }

        return $types[$type];
    }

    /**
     * @return array
     */
    private function getTypes()
    {
        return $this->getContainer()->getParameter(self::EXPORT_TYPES_PARAMETER);
    }

    /**
     * @return string[]
     */
    private function getAvailableTypes()
    {
        return array_keys($this->getTypes());
    }
}
