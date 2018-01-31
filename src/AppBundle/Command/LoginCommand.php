<?php

namespace AppBundle\Command;

use AppBundle\Service\ConfigService;
use AppBundle\Service\CredentialService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use RIPS\Connector\Exceptions\ClientException;

class LoginCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:login')
            ->setDescription('Store credentials in configuration')
            ->addOption('config', 'c', InputOption::VALUE_NONE, 'Try to use password from config (read-only)')
            ->addOption('force', 'f', InputOption::VALUE_NONE)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        // Env variables have the highest priority and override everything else.
        $apiUri = getenv('RIPS_BASE_URI');
        $loginUsername = getenv('RIPS_USERNAME');
        $loginPassword = getenv('RIPS_PASSWORD');

        $helper = $this->getHelper('question');
        $configService = $this->getContainer()->get(ConfigService::class);
        $credentialService = $this->getContainer()->get(CredentialService::class);
        $api = $this->getContainer()->get('rips_connector.api');

        // Windows does not have ca CA bundle, so we have to hard code one.
        $settings = [];

        if (stristr(PHP_OS, 'WIN')) {
            $settings['verify'] = realpath($this->getContainer()->get('kernel')->getRootDir() . '/Resources/cacert.pem');
        }

        if ($input->getOption('config') && $credentialService->hasCredentials()) {
            $credentials = $credentialService->getCredentials();

            if ($apiUri) {
                $credentials['base_uri'] = $apiUri;
            }

            $settings['base_uri'] = $credentials['base_uri'];

            if ($loginUsername) {
                $credentials['username'] = $loginUsername;
            }

            if ($loginPassword) {
                $credentials['password'] = $loginPassword;
            }

            $api->initialize($credentials['username'], $credentials['password'], $settings);
        } else {
            if (!$apiUri) {
                $defaultApiUri = $this->getContainer()->getParameter('default_api_url');
                $loginUriQuestion = new Question('Please enter RIPS-API URL (default: ' . $defaultApiUri . '): ', $defaultApiUri);
                $apiUri = $helper->ask($input, $output, $loginUriQuestion);
            }

            $settings['base_uri'] = $apiUri;

            while (!$loginUsername) {
                $loginUsernameQuestion = new Question('Please enter username: ');
                $loginUsername = $helper->ask($input, $output, $loginUsernameQuestion);
            };

            while (!$loginPassword) {
                $loginPasswordQuestion = new Question('Please enter password: ');
                $loginPasswordQuestion->setHidden(true);
                $loginPasswordQuestion->setHiddenFallback(false);
                $loginPassword = $helper->ask($input, $output, $loginPasswordQuestion);
            };

            try {
                // Before the credentials are stored the user might want to check them first.
                $api->initialize($loginUsername, $loginPassword, $settings);

                $output->writeln('<comment>Info:</comment> Requesting status', OutputInterface::VERBOSITY_VERBOSE);
                $api->getStatus();
                $output->writeln('<info>Success:</info> Authentication successful');
            } catch (ClientException $e) {
                $output->writeln('<error>Failure:</error> Invalid credentials');

                if (!$input->getOption('force')) {
                    return 1;
                }
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> Can\'t connect to the API');

                if (!$input->getOption('force')) {
                    return 1;
                }
            }

            if (!$input->getOption('config')) {
                if ($input->getOption('force')) {
                    $storeConfirmation = true;
                } else {
                    $storeQuestion = new ConfirmationQuestion('Do you really want to store the credentials in ' . $configService->getFile() . '? (y/n) ', false);
                    $storeConfirmation = $helper->ask($input, $output, $storeQuestion);
                }
                if ($storeConfirmation) {
                    $output->writeln('<comment>Info:</comment> Trying to store credentials in ' . $configService->getFile(), OutputInterface::VERBOSITY_VERBOSE);
                    $credentialService->storeCredentials($loginUsername, $loginPassword, $apiUri);
                    $output->writeln('<info>Success:</info> Credentials have been stored successfully');
                }
            }
        }

        return 0;
    }
}
