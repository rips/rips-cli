<?php

namespace App\Command;

use App\Service\ArchiveService;
use RIPS\ConnectorBundle\Entities\LanguageEntity;
use RIPS\ConnectorBundle\Services\LanguageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ZipScanCommand extends Command
{
    /** @var LanguageService */
    private $languageService;

    /** @var ArchiveService */
    private $archiveService;

    public function __construct(LanguageService $languageService, ArchiveService $archiveService)
    {
        $this->languageService = $languageService;
        $this->archiveService = $archiveService;

        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('rips:scan:zip')
            ->setDescription('Create a clean zip file for scanning')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Path to zip file or folder with source code')
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Language that will be scanned (name or ID)'
            )
            ->addOption(
                'output-path',
                'o',
                InputOption::VALUE_REQUIRED,
                'Path to which the resulting archive should be saved'
            )
            ->addOption(
                'extensions',
                'E',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Overrides the file extensions'
            );
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

        if (!$input->getOption('path')) {
            $pathQuestion = new Question('Please enter a path to a folder or a zip file: ');
            $input->setOption('path', $helper->ask($input, $output, $pathQuestion));
        }

        if (!$input->getOption('path')) {
            $output->writeln('<error>Failure:</error> Path can not be empty');
            return 1;
        }

        if (!$input->getOption('output-path')) {
            $pathQuestion = new Question('Please enter a path to which the archive should be saved: ');
            $input->setOption('output-path', $helper->ask($input, $output, $pathQuestion));
        }

        if (!$input->getOption('output-path')) {
            $output->writeln('<error>Failure:</error> Output path can not be empty');
            return 1;
        }

        $language = $input->getOption("language");
        if (!is_string($language)) {
            $language = null;
        }

        if (!$input->getOption('extensions') || !is_array($input->getOption('extensions'))) {
            $languages = $this->languageService->getAll()->getLanguages();
            $extensions = $this->extractExtensions($languages, $language);
            if ($extensions === false) {
                $languageNames = array_map(function (LanguageEntity $language) {
                    return strtolower($language->getName());
                }, $languages);
                $output->writeln(
                    '<error>Failure:</error> The specified language did not match any available language. Available: '
                    . implode(', ', $languageNames)
                );
                return 1;
            }
            $input->setOption('extensions', $extensions);
        }

        $path = (string)$input->getOption('path');
        $this->archiveService->setFileExtensions((array)$input->getOption('extensions'));

        if (is_dir($path)) {
            try {
                $this->archiveService->folderToArchive($path, [], (string)$input->getOption('output-path'));
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> ' . $e->getMessage());
                return 1;
            }
        } elseif ($this->archiveService->isArchive($path)) {
            try {
                $this->archiveService->archiveToArchive($path, [], (string)$input->getOption('output-path'));
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> ' . $e->getMessage());
                return 1;
            }
        } else {
            $output->writeln('<error>Failure:</error> Path is neither a folder nor a ZIP file');
            return 1;
        }

        return 0;
    }

    /**
     * @param LanguageEntity[] $languages
     * @param string|null $chosenLanguage
     * @return bool|string[]
     */
    private function extractExtensions(array $languages, ?string $chosenLanguage = null)
    {
        $result = [];
        $matched = false;
        foreach ($languages as $language) {
            if ($chosenLanguage !== null &&
                (string)$language->getId() !== $chosenLanguage &&
                strtolower($language->getName()) !== strtolower($chosenLanguage)) {
                continue;
            }
            $matched = true;
            $result = array_merge($language->getFileExtensions(), $result);
        }

        if ($chosenLanguage !== null && $matched === false) {
            return false;
        }

        return $result;
    }
}
