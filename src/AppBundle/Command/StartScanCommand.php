<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Filesystem\Filesystem;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\AddBuilder;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\TagBuilder;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\PhpBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use AppBundle\Service\ArchiveService;
use AppBundle\Service\EnvService;
use RIPS\ConnectorBundle\Services\APIService;
use RIPS\ConnectorBundle\Services\Application\ScanService;
use RIPS\ConnectorBundle\Services\Application\UploadService;

class StartScanCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:scan:start')
            ->setDescription('Pack, upload, and scan')
            ->addOption('application', 'a', InputOption::VALUE_REQUIRED, 'Set application id')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Path to project files')
            ->addOption('exclude-path', 'E', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude files from archive with regular expressions')
            ->addOption('upload', 'U', InputOption::VALUE_REQUIRED, 'Set existing upload id')
            ->addOption('name', 'N', InputOption::VALUE_REQUIRED, 'Set version name')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set threshold when the scan should fail (exit code 2)')
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'Set to true if you want to start a scan by local path')
            ->addOption('quota', 'Q', InputOption::VALUE_REQUIRED, 'Set quota id')
            ->addOption('custom', 'C', InputOption::VALUE_REQUIRED, 'Set custom id (analysis profile)')
            ->addOption('remove-upload', 'k', InputOption::VALUE_NONE, 'Remove upload after scan is finished')
            ->addOption('keep-upload', 'K', InputOption::VALUE_NONE, 'Do not remove upload after scan is finished')
            ->addOption('parent', 'P', InputOption::VALUE_REQUIRED, 'Set parent scan id')
            ->addOption('tag', 'T', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add tags')
            ->addOption('env-file', 'F', InputOption::VALUE_REQUIRED, 'Load environment from file')
            ->addOption('remove-code', 'R', InputOption::VALUE_NONE, 'Remove source code from RIPS once analysis is finished')
            ->addOption('keep-code', 'r', InputOption::VALUE_NONE, 'Keep source code in RIPS once analysis is finished')
            ->addOption('issue-type', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override the issue types')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('path') && $input->getOption('upload')) {
            $output->writeln('<error>Failure:</error> Path and upload are not compatible');
            return 1;
        } elseif ($input->getOption('local') && $input->getOption('upload')) {
            $output->writeln('<error>Failure:</error> Local and upload are not compatible');
            return 1;
        } elseif ($input->getOption('local') && $input->getOption('keep-upload')) {
            $output->writeln('<error>Failure:</error> Local and keep-upload are not compatible');
            return 1;
        } elseif ($input->getOption('local') && $input->getOption('exclude-path')) {
            $output->writeln('<error>Failure:</error> Local and exclude-path are not compatible');
            return 1;
        } elseif (!$input->getOption('path') && $input->getOption('exclude-path')) {
            $output->writeln('<error>Failure:</error> Exclude-path requires path');
            return 1;
        } elseif ($input->getOption('remove-code') && $input->getOption('keep-code')) {
            $output->writeln('<error>Failure:</error> Remove-code and keep-code are not compatible');
            return 1;
        } elseif ($input->getOption('remove-upload') && $input->getOption('keep-upload')) {
            $output->writeln('<error>Failure:</error> Remove-upload and keep-upload are not compatible');
            return 1;
        }

        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');

        if (!$path = $input->getOption('path')) {
            // Do not ask for path if upload is used.
            if (!$input->getOption('upload')) {
                $pathQuestion = new Question('Please enter a path: ');
                $path = $helper->ask($input, $output, $pathQuestion);
            }
        }

        if (!$applicationId = $input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $applicationId = $helper->ask($input, $output, $applicationQuestion);
        }

        if (!$version = $input->getOption('name')) {
            $version = date(DATE_ISO8601);
        }

        $scanInput = [
            'version' => $version
        ];

        if ($input->getOption('remove-upload')) {
            $scanInput['uploadRemoved'] = true;
        } else if ($input->getOption('keep-upload')) {
            $scanInput['uploadRemoved'] = false;
        }

        if ($input->getOption('remove-code')) {
            $scanInput['codeStored'] = false;
        } else if ($input->getOption('keep-code')) {
            $scanInput['codeStored'] = true;
        }

        if ($customId = $input->getOption('custom')) {
            $scanInput['custom'] = $customId;
        }

        if ($parentId = $input->getOption('parent')) {
            $scanInput['parent'] = $parentId;
        }

        if ($issueTypes = $input->getOption('issue-type')) {
            $scanInput['issueTypes'] = $issueTypes;
        }

        if ($uploadId = $input->getOption('upload')) {
            $output->writeln('<comment>Info:</comment> Using existing upload "' . $uploadId . '"', OutputInterface::VERBOSITY_VERBOSE);
            $scanInput['upload'] = $uploadId;
        } elseif ($input->getOption('local')) {
            $output->writeln('<comment>Info:</comment> Using local path "' . $path . '"', OutputInterface::VERBOSITY_VERBOSE);
            $scanInput['path'] = $path;
        } else {
            $output->writeln('<comment>Info:</comment> Using path "' . $path . '"', OutputInterface::VERBOSITY_VERBOSE);

            if (!$realPath = realpath($path)) {
                $output->writeln('<error>Failure:</error> Path does not exist');
                return 1;
            }

            // Make sure that we have a supported archive.
            /** @var ArchiveService $archiveService */
            $archiveService = $this->getContainer()->get(ArchiveService::class);

            // Use file extensions from API if it provides them. Otherwise fall back to the internal ones (RCLI-61).
            /** @var APIService $statusService */
            $statusService = $this->getContainer()->get('rips_connector.api');
            $status = $statusService->getStatus();
            if ($status->getFileExtensions()) {
                $archiveService->setFileExtensions($status->getFileExtensions());
            }

            if (!$archiveService->isArchive($path)) {
                $output->writeln('<comment>Info:</comment> Packing folder "' . $realPath . '"', OutputInterface::VERBOSITY_VERBOSE);
                $archivePath = $archiveService->folderToArchive($realPath, $input->getOption('exclude-path'));
                $output->writeln('<comment>Info:</comment> Created archive "' . $archivePath . '" from folder "' . $realPath . '"', OutputInterface::VERBOSITY_VERBOSE);
                $archiveName = basename($archivePath) . '.zip';
                $removeZip = true;
            } else {
                $archivePath = $realPath;
                $archiveName = basename($archivePath);
                $removeZip = false;
            }

            // Upload the archive.
            /** @var UploadService $uploadService */
            $uploadService = $this->getContainer()->get('rips_connector.application.uploads');

            try {
                $output->writeln('<comment>Info:</comment> Starting upload of archive "' . $archivePath . '"', OutputInterface::VERBOSITY_VERBOSE);
                $upload = $uploadService->create($applicationId, $archiveName, $archivePath);
                $output->writeln('<info>Success:</info> Archive "' . $archiveName . '" (' . $upload->getId() . ') was successfully uploaded');
            } catch (\Exception $e) {
                return 1;
            } finally {
                if ($removeZip) {
                    $fs = new Filesystem();
                    $fs->remove($archivePath);
                    $output->writeln('<comment>Info:</comment> Removed archive "' . $archivePath . '"', OutputInterface::VERBOSITY_VERBOSE);
                }
            }

            // Create a new scan by upload.
            $scanInput['upload'] = $upload->getId();
        }

        if ($quotaId = $input->getOption('quota')) {
            $output->writeln('<comment>Info:</comment> Using quota "' . $quotaId . '" to start scan', OutputInterface::VERBOSITY_VERBOSE);
            $scanInput['chargedQuota'] = $quotaId;
        }

        $arrayInput = [
            'scan' => new AddBuilder($scanInput)
        ];

        if ($input->getOption('env-file')) {
            $output->writeln('<comment>Info:</comment> Using env from ' . $input->getOption('env-file'), OutputInterface::VERBOSITY_VERBOSE);
            /** @var EnvService $envService */
            $envService = $this->getContainer()->get(EnvService::class);
            try {
                $arrayInput['php'] = new PhpBuilder($envService->loadEnvFromFile('php', $input->getOption('env-file')));
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> Error opening env file: ' . $e->getMessage());
                return 1;
            }
        }

        if ($input->getOption('tag')) {
            $output->writeln('<comment>Info:</comment> Using tags ' . implode(', ', $input->getOption('tag')), OutputInterface::VERBOSITY_VERBOSE);
            $arrayInput['tags'] = new TagBuilder($input->getOption('tag'));
        }

        /** @var ScanService $scanService */
        $scanService = $this->getContainer()->get('rips_connector.application.scans');

        $output->writeln('<comment>Info:</comment> Trying to start scan "' . $version . '"', OutputInterface::VERBOSITY_VERBOSE);
        $scan = $scanService->create($applicationId, $arrayInput);
        $output->writeln('<info>Success:</info> Scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') was successfully started at ' . $scan->getStart()->format(DATE_ISO8601));

        if ($chargedQuota = $scan->getChargedQuota()) {
            $output->writeln('<comment>Info:</comment> Quota "' . $chargedQuota->getId() . '" was used to start scan "' . $scan->getVersion() . '" (' . $scan->getId() . ')', OutputInterface::VERBOSITY_VERBOSE);
        }

        // Wait for scan to finish if user wants an exit code based on the results.
        $thresholds = $input->getOption('threshold');
        if ($thresholds) {
            $output->writeln('<comment>Info:</comment> Waiting for scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') to finish', OutputInterface::VERBOSITY_VERBOSE);
            $scan = $scanService->blockUntilDone($applicationId, $scan->getId(), 0, 5, [
                'issueNegativelyReviewed' => 0,
                'showScanSeverityDistributions' => 1
            ]);
            $output->writeln('<comment>Info:</comment> Scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') finished at ' . $scan->getFinish()->format(DATE_ISO8601), OutputInterface::VERBOSITY_VERBOSE);

            $severityDistributions = array_change_key_case($scan->getSeverityDistributions());
            $severityDistributions['sum'] = array_reduce(
                array_keys($severityDistributions),
                function ($carry, $key) use ($severityDistributions) {
                    if ($key === 'New') {
                        return $carry;
                    }

                    return $carry + $severityDistributions[$key];
                },
                0
            );

            $exitCode = 0;
            foreach ($thresholds as $threshold) {
                // Turn numbers into sum for backwards compatibility.
                if (is_numeric($threshold)) {
                    $threshold = 'sum:' . $threshold;
                }

                // Separate threshold into category and value.
                try {
                    list($thresholdCategory, $thresholdValue) = explode(":", $threshold, 2);
                } catch (\Exception $e) {
                    $output->writeln('<error>Failure:</error> Invalid threshold ' . $threshold . ' (category:value)');
                    $exitCode = 2;
                    continue;
                }

                if (!isset($severityDistributions[$thresholdCategory])) {
                    $availableCategories = implode(', ', array_keys($severityDistributions));
                    $output->writeln('<error>Failure:</error> Threshold category ' . $thresholdCategory . ' does not exist (' . $availableCategories . ')');
                    $exitCode = 2;
                    continue;
                }

                $issueCount = $severityDistributions[$thresholdCategory];

                if ($issueCount > $thresholdValue) {
                    $output->writeln('<error>Failure:</error> Number of issues exceeds ' . $thresholdCategory . ' threshold (' . $issueCount . '/' . $thresholdValue . ')');
                    $exitCode = 2;
                } else {
                    $output->writeln('<info>Success:</info> Number of issues does not exceed ' . $thresholdCategory . ' threshold (' . $issueCount . '/' . $thresholdValue . ')');
                }
            }
            return $exitCode;
        }

        return 0;
    }
}
