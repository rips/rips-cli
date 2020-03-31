<?php

namespace App\Command;

use App\Service\QuotaService;
use RIPS\ConnectorBundle\Services\ApplicationService;
use RIPS\ConnectorBundle\InputBuilders\ApplicationBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class CreateApplicationCommand extends Command
{
    /** @var ApplicationService */
    private $applicationService;

    /** @var QuotaService */
    private $quotaService;

    /**
     * CreateApplicationCommand constructor.
     * @param ApplicationService $applicationService
     * @param QuotaService $quotaService
     */
    public function __construct(ApplicationService $applicationService, QuotaService $quotaService)
    {
        $this->applicationService = $applicationService;
        $this->quotaService = $quotaService;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:application:create')
            ->setDescription('Create a new application')
            ->addOption('name', 'N', InputOption::VALUE_REQUIRED, 'Set name')
            ->addOption('quota', 'Q', InputOption::VALUE_REQUIRED, 'Set quota id')
            ->addOption('language', 'L', InputOption::VALUE_REQUIRED, 'Set language')
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
        if ($input->getOption('quota') && $input->getOption('language')) {
            $output->writeln('<error>Failure:</error> Quota and language are not compatible');
            return 1;
        }

        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('name')) {
            $nameQuestion = new Question('Please enter a name: ');
            $input->setOption('name', $helper->ask($input, $output, $nameQuestion));
        }

        if (!$input->getOption('name')) {
            $output->writeln('<error>Failure:</error> Name can not be empty');
            return 1;
        }

        $applicationInput = new ApplicationBuilder();

        $name = (string)$input->getOption('name');
        $applicationInput->setName($name);

        $quota = (int)$input->getOption('quota');
        $language = (string)$input->getOption('language');

        if ($language) {
            $quota = $this->quotaService->getQuotaForLanguage($language);
            if (!$quota) {
                $output->writeln('<error>Failure:</error> Could not find a valid quota for language ' . $language);
                return 1;
            }
        }

        if ($quota) {
            $output->writeln('<comment>Info:</comment> Using quota "' . $quota->getId() . '" to create application', OutputInterface::VERBOSITY_VERBOSE);
            $applicationInput->setChargedQuota($quota->getId());
        }

        $output->writeln('<comment>Info:</comment> Trying to create application "' . $name . '"', OutputInterface::VERBOSITY_VERBOSE);

        $application = $this->applicationService->create($applicationInput)->getApplication();

        $output->writeln('<info>Success:</info> Application "' . $application->getName() . '" (' . $application->getId() . ') was created at ' . $application->getCreatedAt()->format(DATE_ISO8601));

        $chargedQuota = $application->getChargedQuota();
        $output->writeln('<comment>Info:</comment> Quota "' . $chargedQuota->getId() . '" was used to create application "' . $application->getName() . '" (' . $application->getId() . ')', OutputInterface::VERBOSITY_VERBOSE);

        return 0;
    }
}
