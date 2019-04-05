<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Filesystem\Filesystem;
use RIPS\ConnectorBundle\Entities\Application\ScanEntity;
use RIPS\ConnectorBundle\Entities\Application\UploadEntity;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\TagBuilder;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\PhpBuilder;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\JavaBuilder;
use RIPS\ConnectorBundle\InputBuilders\Application\ScanBuilder;
use RIPS\ConnectorBundle\Services\Application\ScanService;
use RIPS\ConnectorBundle\Services\Application\UploadService;
use RIPS\ConnectorBundle\Services\ApplicationService;
use RIPS\ConnectorBundle\Services\LanguageService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use AppBundle\Service\ArchiveService;
use AppBundle\Service\EnvService;

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
            ->addOption('profile', 'C', InputOption::VALUE_REQUIRED, 'Set analysis profile id')
            ->addOption('remove-upload', 'k', InputOption::VALUE_NONE, 'Remove upload after scan is finished')
            ->addOption('keep-upload', 'K', InputOption::VALUE_NONE, 'Do not remove upload after scan is finished')
            ->addOption('parent', 'P', InputOption::VALUE_REQUIRED, 'Set parent scan id')
            ->addOption('tag', 'T', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add tags')
            ->addOption('env-file', 'F', InputOption::VALUE_REQUIRED, 'Load environment from file')
            ->addOption('remove-code', 'R', InputOption::VALUE_NONE, 'Remove source code from RIPS once analysis is finished')
            ->addOption('keep-code', 'r', InputOption::VALUE_NONE, 'Keep source code in RIPS once analysis is finished')
            ->addOption('issue-type', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override the issue types')
            ->addOption('source', 'S', InputOption::VALUE_REQUIRED, 'Modify the source of the scan', 'rips-cli')
            ->addOption('progress', 'G', InputOption::VALUE_NONE, 'Show progress bar')
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

        if (!$input->getOption('path') && !$input->getOption('upload')) {
            $pathQuestion = new Question('Please enter a path: ');
            $input->setOption('path', $helper->ask($input, $output, $pathQuestion));
        }

        if (!$input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $input->setOption('application', $helper->ask($input, $output, $applicationQuestion));
        }

        $scanInput = new ScanBuilder();

        if ($input->getOption('name')) {
            $scanInput->setVersion((string)$input->getOption('name'));
        } else {
            $scanInput->setVersion(date('Y-m-d H:i'));
        }

        if ($input->getOption('remove-upload')) {
            $scanInput->setUploadRemoved(true);
        } elseif ($input->getOption('keep-upload')) {
            $scanInput->setUploadRemoved(false);
        }

        if ($input->getOption('remove-code')) {
            $scanInput->setCodeStored(false);
        } elseif ($input->getOption('keep-code')) {
            $scanInput->setCodeStored(true);
        }

        if ($input->getOption('profile')) {
            $scanInput->setProfile((int)$input->getOption('profile'));
        }

        if ($input->getOption('parent')) {
            $scanInput->setParent((int)$input->getOption('parent'));
        }

        if ($input->getOption('issue-type')) {
            $scanInput->setIssueTypes((array)$input->getOption('issue-type'));
        }

        if ($input->getOption('source')) {
            $scanInput->setSource((string)$input->getOption('source'));
        }

        $path = (string)$input->getOption('path');
        $applicationId = (int)$input->getOption('application');
        $uploadId = (int)$input->getOption('upload');

        if ($uploadId) {
            $output->writeln('<comment>Info:</comment> Using existing upload "' . $uploadId . '"', OutputInterface::VERBOSITY_VERBOSE);
            $scanInput->setUpload($uploadId);
        } elseif ($input->getOption('local')) {
            $output->writeln('<comment>Info:</comment> Using local path "' . $path . '"', OutputInterface::VERBOSITY_VERBOSE);
            $scanInput->setPath($path);
        } else {
            $output->writeln('<comment>Info:</comment> Using path "' . $path . '"', OutputInterface::VERBOSITY_VERBOSE);

            if (!$realPath = realpath($path)) {
                $output->writeln('<error>Failure:</error> Path does not exist');
                return 1;
            }

            $upload = $this->uploadPath($applicationId, $realPath, (array)$input->getOption('exclude-path'));
            if (!$upload) {
                $output->writeln('<error>Error:</error> Could not upload archive');
                return 1;
            }

            $output->writeln('<info>Success:</info> Archive "' . $upload->getName() . '" (' . $upload->getId() . ') was successfully uploaded');
            $scanInput->setUpload($upload->getId());
        }

        $arrayInput = ['scan' => $scanInput];

        /** @var string $envFile */
        $envFile = (string)$input->getOption('env-file');
        if ($envFile) {
            $output->writeln('<comment>Info:</comment> Using env from ' . $envFile, OutputInterface::VERBOSITY_VERBOSE);
            try {
                /** @var EnvService $envService */
                $envService = $this->getContainer()->get(EnvService::class);

                $languageEnvs = [
                    'php' => PhpBuilder::class,
                    'java' => JavaBuilder::class
                ];

                foreach ($languageEnvs as $languageEnvKey => $languageEnvClass) {
                    if (!$envService->hasEnv($languageEnvKey, $envFile)) {
                        continue;
                    }
                    $arrayInput[$languageEnvKey] = new $languageEnvClass(
                        $envService->loadEnvFromFile($languageEnvKey, $envFile)
                    );
                }
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> Error opening env file: ' . $e->getMessage());
                return 1;
            }
        }

        /** @var array $tags */
        $tags = (array)$input->getOption('tag');
        if ($tags) {
            $output->writeln('<comment>Info:</comment> Using tags ' . implode(', ', $tags), OutputInterface::VERBOSITY_VERBOSE);
            $arrayInput['tags'] = new TagBuilder($tags);
        }

        /** @var ScanService $scanService */
        $scanService = $this->getContainer()->get(ScanService::class);

        $output->writeln('<comment>Info:</comment> Trying to start scan', OutputInterface::VERBOSITY_VERBOSE);
        $scan = $scanService->create($applicationId, $arrayInput)->getScan();
        $output->writeln('<info>Success:</info> Scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') was successfully started at ' . $scan->getStartedAt()->format(DATE_ISO8601));

        if ($input->getOption('progress')) {
            $this->blockAndShowProgress($output, $scanService, $applicationId, $scan);
        }

        // Wait for scan to finish if user wants an exit code based on the results.
        $thresholds = (array)$input->getOption('threshold');
        if ($thresholds) {
            $output->writeln('<comment>Info:</comment> Waiting for scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') to finish', OutputInterface::VERBOSITY_VERBOSE);
            $scan = $scanService->blockUntilDone($applicationId, $scan->getId(), 0, 5, [
                'customFilter' => json_encode([
                    'severityDistribution' => [
                        'show' => true,
                        'negativelyReviewed' => false
                    ]
                ])
            ])->getScan();
            $output->writeln('<comment>Info:</comment> Scan "' . $scan->getVersion() . '" (' . $scan->getId() . ') finished at ' . $scan->getFinishedAt()->format(DATE_ISO8601), OutputInterface::VERBOSITY_VERBOSE);

            return $this->checkScanThresholds($output, $scan, $thresholds);
        }

        return 0;
    }

    /**
     * @param int $applicationId
     * @return array
     */
    private function getFileExtensions($applicationId)
    {
        /** @var ApplicationService $applicationService */
        $applicationService = $this->getContainer()->get(ApplicationService::class);
        $application = $applicationService->getById($applicationId)->getApplication();

        /** @var LanguageService $languageService */
        $languageService = $this->getContainer()->get(LanguageService::class);

        $fileExtensions = [];
        foreach ($application->getChargedQuota()->getLanguages() as $language) {
            $language = $languageService->getById($language->getId())->getLanguage();
            $fileExtensions = array_unique(array_merge($fileExtensions, $language->getFileExtensions()));
        }
        return $fileExtensions;
    }

    /**
     * @param int $applicationId
     * @param string $path
     * @param array $excludePath
     * @return UploadEntity|null
     * @throws \Exception
     */
    private function uploadPath($applicationId, $path, $excludePath = [])
    {
        /** @var ArchiveService $archiveService */
        $archiveService = $this->getContainer()->get(ArchiveService::class);

        // Use file extensions from API if it provides them. Otherwise fall back to the internal ones (RCLI-61).
        $fileExtensions = $this->getFileExtensions($applicationId);
        if (!empty($fileExtensions)) {
            $archiveService->setFileExtensions($fileExtensions);
        }

        if (!$archiveService->isArchive($path)) {
            $archivePath = $archiveService->folderToArchive($path, $excludePath);
            $archiveName = basename($archivePath) . '.zip';
            $removeZip = true;
        } else {
            $archiveName = basename($path);
            $archivePath = $path;
            $removeZip = false;
             // If it is a .zip, upload a cleaned version.
            if ($archiveService->isZipArchive($archivePath)) {
                $archivePath = $archiveService->archiveToArchive($archivePath, $excludePath);
                $removeZip = true;
            }
        }

        /** @var UploadService $uploadService */
        $uploadService = $this->getContainer()->get(UploadService::class);

        try {
            return $uploadService->create($applicationId, $archiveName, $archivePath)->getUpload();
        } catch (\Exception $e) {
            return null;
        } finally {
            if ($removeZip) {
                $fs = new Filesystem();
                $fs->remove($archivePath);
            }
        }
    }

    /**
     * @param OutputInterface $output
     * @param ScanEntity $scan
     * @param string[] $thresholds
     * @return int
     */
    private function checkScanThresholds($output, $scan, $thresholds)
    {
        $severityDistributions = json_decode(json_encode($scan->getSeverityDistributions()['total']), true);
        $severityDistributions['sum'] = array_sum($severityDistributions);

        $severityDistributionsNew = json_decode(json_encode($scan->getSeverityDistributions()['new']), true);
        $severityDistributions['new'] = array_sum($severityDistributionsNew);
        $severityDistributions['new-critical'] = $severityDistributionsNew['critical'];
        $severityDistributions['new-high'] = $severityDistributionsNew['high'];
        $severityDistributions['new-medium'] = $severityDistributionsNew['medium'];
        $severityDistributions['new-low'] = $severityDistributionsNew['low'];

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

    /**
     * @param OutputInterface $output
     * @param ScanService $scanService
     * @param int $applicationId
     * @param ScanEntity $scan
     */
    private function blockAndShowProgress(OutputInterface $output, ScanService $scanService, $applicationId, ScanEntity $scan)
    {
        $progressBar = new ProgressBar($output, 100);
        $progressBar->setFormat("Progress: [%bar%] %percent%%");

        $progressBar->start();

        // Loop and update progress until we hit 100% or the scan's phase is an exit phase.
        do {
            sleep(5);
            $scan = $scanService->getById($applicationId, $scan->getId())->getScan();
            $progress = $scan->getPercent();
            $phase = $scan->getPhase();
            $progressBar->setProgress($progress);
        } while ($progress < 100 && !in_array($phase, [0, 6, 7], true));
        $progressBar->finish();
    }
}
