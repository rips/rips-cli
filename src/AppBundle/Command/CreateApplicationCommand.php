<?php

namespace AppBundle\Command;

use RIPS\ConnectorBundle\Entities\QuotaEntity;
use RIPS\ConnectorBundle\InputBuilders\FilterBuilder;
use RIPS\ConnectorBundle\Services\ApplicationService;
use RIPS\ConnectorBundle\Services\QuotaService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use RIPS\ConnectorBundle\InputBuilders\ApplicationBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class CreateApplicationCommand extends ContainerAwareCommand
{
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
        $language = $input->getOption('language');

        if ($language) {
            $quota = $this->getQuotaIdByLanguage($language);
            if (!$quota) {
                $output->writeln('<error>Failure:</error> Could not find a valid quota for ' . $language);
                return 1;
            }
        }

        if ($quota) {
            $output->writeln('<comment>Info:</comment> Using quota "' . $quota . '" to create application', OutputInterface::VERBOSITY_VERBOSE);
            $applicationInput->setChargedQuota($quota);
        }

        $output->writeln('<comment>Info:</comment> Trying to create application "' . $name . '"', OutputInterface::VERBOSITY_VERBOSE);

        /** @var ApplicationService $applicationService */
        $applicationService = $this->getContainer()->get(ApplicationService::class);
        $application = $applicationService->create($applicationInput)->getApplication();

        $output->writeln('<info>Success:</info> Application "' . $application->getName() . '" (' . $application->getId() . ') was created at ' . $application->getCreatedAt()->format(DATE_ISO8601));

        $chargedQuota = $application->getChargedQuota();
        if ($chargedQuota) {
            $output->writeln('<comment>Info:</comment> Quota "' . $chargedQuota->getId() . '" was used to create application "' . $application->getName() . '" (' . $application->getId() . ')', OutputInterface::VERBOSITY_VERBOSE);
        }

        return 0;
    }

    /**
     * Try to find the first quota that expires and supports the given language.
     *
     * @param string $language
     * @return int|null
     * @throws \Exception
     */
    private function getQuotaIdByLanguage($language)
    {
        $now = new \DateTime();

        $filterBuilder = new FilterBuilder();
        $condition = $filterBuilder->and(
            $filterBuilder->greaterThan('validFrom', $now->format(DATE_ISO8601)),
            $filterBuilder->lessThan('validUntil', $now->format(DATE_ISO8601))
        );

        /** @var QuotaService $quotaService */
        $quotaService = $this->getContainer()->get(QuotaService::class);

        $quotas = $quotaService->getAll([
            'filter'  => $filterBuilder->getFilterString($condition),
            'orderBy' => json_encode(['validUntil' => 'desc'])
        ]);

        foreach ($quotas as $quota) {
            /** @var QuotaEntity $quota */
            if ($quota->getCurrentApplication() >= $quota->getMaxApplications()) {
                continue;
            }

            foreach ($quota->getLanguages() as $quotaLanguage) {
                $idMatch = $quotaLanguage->getId() === (int)$language;
                $nameMatch = strtolower($quotaLanguage->getName()) === strtolower($language);
                if ($idMatch || $nameMatch) {
                    return $quota->getId();
                }
            }
        }

        return null;
    }
}
