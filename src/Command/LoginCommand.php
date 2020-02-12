<?php

namespace App\Command;

use App\Service\ConfigService;
use App\Service\CredentialService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use RIPS\Connector\Exceptions\ClientException;
use RIPS\ConnectorBundle\Services\APIService;
use RIPS\ConnectorBundle\Entities\StatusEntity;

class LoginCommand extends Command
{
    const MAJOR_VERSION = 3;

    /** @var ContainerInterface */
    private $container;

    /** @var ConfigService */
    private $configService;

    /** @var CredentialService */
    private $credentialService;

    /** @var APIService */
    private $api;

    /**
     * LoginCommand constructor.
     * @param ContainerInterface $container
     * @param ConfigService $configService
     * @param CredentialService $credentialService
     * @param APIService $api
     */
    public function __construct(
        ContainerInterface $container,
        ConfigService $configService,
        CredentialService $credentialService,
        APIService $api
    )
    {
        $this->container = $container;
        $this->configService = $configService;
        $this->credentialService = $credentialService;
        $this->api = $api;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:login')
            ->setDescription('Store credentials in configuration')
            ->addOption('config', 'c', InputOption::VALUE_NONE, 'Try to use password from config (read-only)')
            ->addOption('force', 'f', InputOption::VALUE_NONE)
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
        // Env variables have the highest priority and override everything else.
        $apiUri = getenv('RIPS_BASE_URI');
        $loginEmail = getenv('RIPS_EMAIL');
        $loginPassword = getenv('RIPS_PASSWORD');
        $mfaToken = getenv('RIPS_MFA_TOKEN');

        $helper = $this->getHelper('question');

        $settings = [
            'timeout' => 0
        ];

        $clientSettings = [];
        if ($mfaToken) {
            $clientSettings['mfa']['token'] = $mfaToken;
            $clientSettings['mfa']['enabled'] = true;
        }

        if (getenv('RIPS_INSECURE_DISABLE_SSL_VERIFICATION')) {
            $output->writeln('<error>Warning:</error> SSL verification is disabled');
            $settings['verify'] = false;
        } else if (stristr(PHP_OS, 'WIN')) {
            /** @var KernelInterface $kernel */
            $kernel = $this->container->get('kernel');
            // Windows does not have ca CA bundle, so we have to hard code one.
            $settings['verify'] = realpath($kernel->getProjectDir() . '/src/Resources/cacert.pem');
        }

        if ($input->getOption('config') && $this->credentialService->hasCredentials()) {
            $credentials = $this->credentialService->getCredentials();

            if ($apiUri) {
                $credentials['base_uri'] = $apiUri;
            }

            $settings['base_uri'] = $credentials['base_uri'];

            if ($loginEmail) {
                $credentials['email'] = $loginEmail;
            }

            if ($loginPassword) {
                $credentials['password'] = $loginPassword;
            }

            if (!$this->verifyBaseUri($output, $settings['base_uri'])) {
                return 1;
            }

            $this->api->initialize($credentials['email'], $credentials['password'], $settings, $clientSettings);
        } else {
            if (!$apiUri) {
                $defaultApiUri = $this->container->getParameter('default_api_url');
                $loginUriQuestion = new Question('Please enter RIPS-API URL (default: ' . $defaultApiUri . '): ', $defaultApiUri);
                $apiUri = $helper->ask($input, $output, $loginUriQuestion);
            }

            $settings['base_uri'] = $apiUri;

            while (!$loginEmail) {
                $loginEmailQuestion = new Question('Please enter e-mail: ');
                $loginEmail = $helper->ask($input, $output, $loginEmailQuestion);
            };

            while (!$loginPassword) {
                $loginPasswordQuestion = new Question('Please enter password: ');
                $loginPasswordQuestion->setHidden(true);
                $loginPasswordQuestion->setHiddenFallback(false);
                $loginPassword = $helper->ask($input, $output, $loginPasswordQuestion);
            };

            try {
                if (!$this->verifyBaseUri($output, $settings['base_uri'])) {
                    return 1;
                }

                // Before the credentials are stored the user might want to check them first.
                $this->api->initialize($loginEmail, $loginPassword, $settings, $clientSettings);

                $output->writeln('<comment>Info:</comment> Requesting status', OutputInterface::VERBOSITY_VERBOSE);
                $status = $this->api->getStatus()->getStatus();

                if (!$this->validMajorVersion($status)) {
                    $output->writeln('<error>Failure:</error> API version not compatible with client');

                    if (!$input->getOption('force')) {
                        return 1;
                    }
                }

                $output->writeln('<info>Success:</info> Authentication successful');
            } catch (ClientException $e) {
                $output->writeln('<error>Failure:</error> Invalid credentials');

                if (!$input->getOption('force')) {
                    return 1;
                }
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> Can\'t connect to the API');
                $output->writeln($e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);

                if (!$input->getOption('force')) {
                    return 1;
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>Failure:</error> The API is not compatible');
                $output->writeln($e->getMessage(), OutputInterface::VERBOSITY_VERBOSE);

                if (!$input->getOption('force')) {
                    return 1;
                }
            }

            if (!$input->getOption('config')) {
                if ($input->getOption('force')) {
                    $storeConfirmation = true;
                } else {
                    $storeQuestion = new ConfirmationQuestion('Do you really want to store the credentials in ' . $this->configService->getFile() . '? (y/n) ', false);
                    $storeConfirmation = $helper->ask($input, $output, $storeQuestion);
                }
                if ($storeConfirmation) {
                    $output->writeln('<comment>Info:</comment> Trying to store credentials in ' . $this->configService->getFile(), OutputInterface::VERBOSITY_VERBOSE);
                    $this->credentialService->storeCredentials($loginEmail, $loginPassword, $apiUri);
                    $output->writeln('<info>Success:</info> Credentials have been stored successfully');
                }
            }
        }

        return 0;
    }

    /**
     * @param StatusEntity $status
     * @return bool
     */
    private function validMajorVersion($status)
    {
        $version = $status->getVersion();
        $versionParts = explode('.', $version);

        return intval($versionParts[0]) === self::MAJOR_VERSION;
    }

    /**
     * @param OutputInterface $output
     * @param string $baseUri
     * @return bool
     */
    private function verifyBaseUri(OutputInterface $output, $baseUri)
    {
        $output->writeln('<comment>Info:</comment> Verifying base uri ' . $baseUri, OutputInterface::VERBOSITY_VERBOSE);

        if (empty($baseUri)) {
            $output->writeln('<error>Error:</error> The base uri is empty');
            return false;
        }

        $parsedUrl = parse_url($baseUri);

        if (empty($parsedUrl) || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
            $output->writeln('<error>Error:</error> The base uri ' . $baseUri . ' is not valid');
            return false;
        }

        return true;
    }
}
