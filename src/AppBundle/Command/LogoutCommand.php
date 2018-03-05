<?php

namespace AppBundle\Command;

use AppBundle\Service\CredentialService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class LogoutCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:logout')
            ->setDescription('Remove credentials from configuration')
            ->addOption('force', 'f', InputOption::VALUE_NONE)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');

        if (!$input->getOption('force')) {
            $removeQuestion = new ConfirmationQuestion('Do you really want to remove the credentials? (y/n) ', false);
            $removeConfirmation = $helper->ask($input, $output, $removeQuestion);
            if (!$removeConfirmation) {
                return 0;
            }
        }

        $output->writeln('<comment>Info:</comment> Trying to remove credentials', OutputInterface::VERBOSITY_VERBOSE);
        /** @var CredentialService $credentialService */
        $credentialService = $this->getContainer()->get(CredentialService::class);
        $credentialService->removeCredentials();
        $output->writeln('<info>Success:</info> Logout successful');

        return 0;
    }
}
