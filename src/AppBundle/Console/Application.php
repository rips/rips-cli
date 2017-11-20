<?php

namespace AppBundle\Console;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use RIPS\Connector\Exceptions\ClientException;
use RIPS\Connector\Exceptions\ServerException;

class Application extends BaseApplication
{
    private $kernel;
    private $commandsRegistered = false;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;

        parent::__construct('rips-cli');

        $this->getDefinition()->addOption(new InputOption('--env', '-e', InputOption::VALUE_REQUIRED, 'The environment name', $kernel->getEnvironment()));
        $this->getDefinition()->addOption(new InputOption('--no-debug', null, InputOption::VALUE_NONE, 'Switches off debug mode'));
    }

    /**
     * Gets the Kernel associated with this Console.
     *
     * @return KernelInterface A KernelInterface instance
     */
    public function getKernel()
    {
        return $this->kernel;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->kernel->boot();

        $container = $this->kernel->getContainer();

        foreach ($this->all() as $command) {
            if ($command instanceof ContainerAwareInterface) {
                $command->setContainer($container);
            }
        }

        /** @var \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $this->setDispatcher($dispatcher);

        try {
            $status = parent::doRun($input, $output);
        } catch (ClientException $e) {
            switch ($e->getCode()) {
                case '400':
                    $output->write('<error>INPUT ERROR:</error>');
                    break;
                case '401':
                    $output->write('<error>UNAUTHORIZED:</error>');
                    break;
                case '403':
                    $output->write('<error>ACCESS FORBIDDEN:</error>');
                    break;
                case '404':
                    $output->write('<error>ITEM NOT FOUND:</error>');
                    break;
                case '423':
                    $output->write('<error>RESOURCE IS LOCKED:</error>');
                    break;
                case '429':
                    $output->write('<error>TOO MANY REQUESTS:</error>');
                    break;
                default:
                    $output->write('<error>HTTP ERROR ' . $e->getCode() . ':</error>');
                    break;
            }

            $decoded = json_decode($e->getMessage(), true);

            if ($decoded) {
                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    $output->writeln(' ' . $decoded['message']);
                }

                if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                    $output->writeln("\n" . print_r($decoded['errors'], true));
                }
            } else {
                $output->writeln('');
            }

            return 1;
        } catch (ServerException $e) {
            switch ($e->getCode()) {
                case '500':
                    $output->write('<error>SERVER ERROR:</error>');
                    break;
                default:
                    $output->write('<error>HTTP ERROR ' . $e->getCode() . ':</error>');
                    break;
            }

            $decoded = json_decode($e->getMessage(), true);

            if ($decoded) {
                if (isset($decoded['message']) && is_string($decoded['message'])) {
                    $output->writeln(' ' . $decoded['message']);
                }
            } else {
                $output->writeln('');
            }

            $output->writeln('To resolve this issue please try to rerun the command.');
            $output->writeln('If this does not resolve the problem you should try to decrease the');
            $output->writeln('impact of your request, for example with a filter like "-p limit=99".');

            return 1;
        }

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    public function find($name)
    {
        $this->registerCommands();

        return parent::find($name);
    }

    /**
     * {@inheritdoc}
     */
    public function get($name)
    {
        $this->registerCommands();

        return parent::get($name);
    }

    /**
     * {@inheritdoc}
     */
    public function all($namespace = null)
    {
        $this->registerCommands();

        return parent::all($namespace);
    }

    /**
     * {@inheritdoc}
     */
    public function getLongVersion()
    {
        return parent::getLongVersion().sprintf(
            ' (kernel: <comment>%s</comment>, env: <comment>%s</comment>, debug: <comment>%s</comment>)',
            $this->kernel->getName(),
            $this->kernel->getEnvironment(),
            ($this->kernel->isDebug() ? 'true' : 'false')
        );
    }

    public function add(Command $command)
    {
        $this->registerCommands();

        return parent::add($command);
    }

    protected function registerCommands()
    {
        if ($this->commandsRegistered) {
            return;
        }

        $this->commandsRegistered = true;

        $this->kernel->boot();

        $container = $this->kernel->getContainer();

        foreach ($this->kernel->getBundles() as $bundle) {
            if ($bundle instanceof Bundle) {
                if ($this->kernel->getEnvironment() === 'prod') {
                    if (!in_array($bundle->getName(), ['AppBundle'])) {
                        continue;
                    }
                }

                $bundle->registerCommands($this);
            }
        }

        if ($container->hasParameter('console.command.ids')) {
            foreach ($container->getParameter('console.command.ids') as $id) {
                /** @var Command $command */
                $command = $container->get($id);
                $this->add($command);
            }
        }
    }
}
