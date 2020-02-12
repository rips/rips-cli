<?php

namespace App\Command;

use App\Service\RequestService;
use RIPS\ConnectorBundle\Services\Application\Scan\ExportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ExportScanCommand extends Command
{
    const EXPORT_TYPES_PARAMETER = 'export_types';

    /** @var ContainerInterface */
    private $container;

    /** @var RequestService */
    private $requestService;

    /** @var ExportService */
    private $exportService;

    /**
     * ExportScanCommand constructor.
     * @param ContainerInterface $container
     * @param RequestService $requestService
     * @param ExportService $exportService
     */
    public function __construct(
        ContainerInterface $container,
        RequestService $requestService,
        ExportService $exportService
    ) {
        $this->container = $container;
        $this->requestService = $requestService;
        $this->exportService = $exportService;

        parent::__construct();
    }

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

        $type = (string)$input->getOption('type');
        $typeData = $this->getType($type);
        $applicationId = (int)$input->getOption('application');
        $scanId = (int)$input->getOption('scan');
        $file = (string)$input->getOption('file');

        if (!$file) {
            // Create file name in case --no-interaction is used.
            $file = $applicationId . '_' . $scanId . '.' . $typeData['extension'];
        }

        $queryParams = $this->requestService->transformParametersForQuery((array)$input->getOption('parameter'));

        $output->writeln('<comment>Info:</comment> Scan is being exported to "' . $file . '"', OutputInterface::VERBOSITY_VERBOSE);

        call_user_func([$this->exportService, $typeData['method']], $applicationId, $scanId, $file, $queryParams);

        $output->writeln('<info>Success:</info> Scan was successfully exported to "' . $file . '"');

        return 0;
    }

    /**
     * @param string $type
     * @return array
     */
    private function getType($type)
    {
        $types = $this->getTypes();

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
        return $this->container->getParameter(self::EXPORT_TYPES_PARAMETER);
    }

    /**
     * @return string[]
     */
    private function getAvailableTypes()
    {
        return array_keys($this->getTypes());
    }
}
