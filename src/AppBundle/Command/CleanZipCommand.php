<?php

namespace AppBundle\Command;

use AppBundle\Service\ArchiveService;
use RIPS\ConnectorBundle\Services\LanguageService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class CleanZipCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:clean-zip')
            ->setDescription('Create a zip file containing only the files with handled extensions')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Path to folder')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED,
                'Language that will be scanned (name or ID)')
            ->addOption('output-path', 'o', InputOption::VALUE_REQUIRED,
                'Path to which the resulting archive should be saved')
            ->addOption('extensions', 'E',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extensions');
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

        if (!$input->getOption('extensions')) {
            /** @var $languagesService $languagesService */
            $languagesService = $this->getContainer()->get(LanguageService::class);
            $languages = $languagesService->getAll()->getLanguages();
            $extensions = $this->extractExtensions($languages, $input->getOption("language"));
            if ($extensions === false) {
                $languageNames = array_map(function ($language) {
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

        $path = $input->getOption('path');
        /** @var ArchiveService $archiveService */
        $archiveService = $this->getContainer()->get(ArchiveService::class);
        $archiveService->setFileExtensions($input->getOption('extensions'));

        if (is_dir($path)) {
            try {
                $archiveService->folderToArchive($path, [], $input->getOption('output-path'));
            } catch (\Exception $e) {
                $output->writeln('<error>Failure:</error> ' . $e->getMessage());
                return 1;
            }
        } elseif ($archiveService->isArchive($path)) {
            try {
                $archiveService->archiveToArchive($path, [], $input->getOption('output-path'));
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
     * @param \RIPS\ConnectorBundle\Entities\LanguageEntity[] $languages
     * @param string|int|null $chosenLanguage
     * @return bool|array
     */
    private function extractExtensions($languages, $chosenLanguage = null)
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
