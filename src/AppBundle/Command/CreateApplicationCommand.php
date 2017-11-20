<?php

namespace AppBundle\Command;

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
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');

        if (!$name = $input->getOption('name')) {
            $nameQuestion = new Question('Please enter a name: ');
            $name = $helper->ask($input, $output, $nameQuestion);
        }

        if (!$name) {
            $output->writeln('<error>Failure:</error> Name can not be empty');
            return 1;
        }

        $applicationInput = [
            'name' => $name
        ];

        if ($quota = $input->getOption('quota')) {
            $output->writeln('<comment>Info:</comment> Using quota "' . $quota . '" to create application', OutputInterface::VERBOSITY_VERBOSE);
            $applicationInput['chargedQuota'] = $quota;
        }

        /** @var \RIPS\ConnectorBundle\Services\ApplicationService $applicationService */
        $applicationService = $this->getContainer()->get('rips_connector.applications');

        $output->writeln('<comment>Info:</comment> Trying to create application "' . $name . '"', OutputInterface::VERBOSITY_VERBOSE);
        $application = $applicationService->create(
            new ApplicationBuilder($applicationInput)
        );
        $output->writeln('<info>Success:</info> Application "' . $application->getName() . '" (' . $application->getId() . ') was created at ' . $application->getCreation()->format(DATE_ISO8601));

        if ($chargedQuota = $application->getChargedQuota()) {
            $output->writeln('<comment>Info:</comment> Quota "' . $chargedQuota->getId() . '" was used to create application "' . $application->getName() . '" (' . $application->getId() . ')', OutputInterface::VERBOSITY_VERBOSE);
        }

        return 0;
    }
}
