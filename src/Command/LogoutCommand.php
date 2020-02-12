<?php

namespace App\Command;

use App\Service\CredentialService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class LogoutCommand extends Command
{
    /** @var CredentialService */
    private $credentialService;

    /**
     * LogoutCommand constructor.
     * @param CredentialService $credentialService
     */
    public function __construct(
        CredentialService $credentialService
    )
    {
        $this->credentialService = $credentialService;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:logout')
            ->setDescription('Remove credentials from configuration')
            ->addOption('force', 'f', InputOption::VALUE_NONE)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
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
        $this->credentialService->removeCredentials();
        $output->writeln('<info>Success:</info> Logout successful');

        return 0;
    }
}
