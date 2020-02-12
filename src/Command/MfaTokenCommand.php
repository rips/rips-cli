<?php

namespace App\Command;

use RIPS\Connector\Exceptions\ClientException;
use RIPS\ConnectorBundle\InputBuilders\User\Mfa\ChallengeBuilder;
use RIPS\ConnectorBundle\Services\MfaService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;

class MfaTokenCommand extends Command
{
    /** @var MfaService */
    private $mfaService;

    /**
     * MfaTokenCommand constructor.
     * @param MfaService $mfaService
     */
    public function __construct(MfaService $mfaService)
    {
        $this->mfaService = $mfaService;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:mfa:token')
            ->setDescription('Fetch MFA token')
            ->addOption('code', 'C', InputOption::VALUE_REQUIRED, 'Set code')
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

        try {
            $mfaInitialized = false;
            $this->mfaService->getSecret()->getMfa();
        } catch (ClientException $exception) {
            $mfaInitialized = true;
        }

        if (!$mfaInitialized) {
            $output->writeln('<error>Failure:</error> MFA not initialized yet');
            return 1;
        }

        if (!$input->getOption('code')) {
            $codeQuestion = new Question('Please enter the code: ');
            $input->setOption('code', $helper->ask($input, $output, $codeQuestion));
        }

        if (!$input->getOption('code')) {
            $output->writeln('<error>Failure:</error> Code can not be empty');
            return 1;
        }

        $challengeInput = new ChallengeBuilder();

        $code = (string)$input->getOption('code');
        $challengeInput->setCode($code);

        try {
            $mfa = $this->mfaService->getToken($challengeInput)->getMfa();
        } catch (ClientException $exception) {
            $error = rtrim(strtolower($exception->getMessage()), '.');
            $output->writeln('<error>Failure:</error> Code not accepted (' . $error . ')');
            return 1;
        }

        $output->writeln('<info>Success:</info> Your MFA token: ' . $mfa->getToken());

        return 0;
    }
}
