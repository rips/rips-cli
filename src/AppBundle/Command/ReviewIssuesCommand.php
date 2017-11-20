<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\Question;
use RIPS\ConnectorBundle\InputBuilders\Application\Scan\Issue\ReviewBuilder;

class ReviewIssuesCommand extends ContainerAwareCommand
{
    public function configure()
    {
        $this
            ->setName('rips:issues:review')
            ->setDescription('Mass review of issues')
            ->addOption('application', 'a', InputOption::VALUE_REQUIRED, 'Set application id')
            ->addOption('scan', 's', InputOption::VALUE_REQUIRED, 'Set scan id')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Set review type id')
            ->addOption('parameter', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add optional query parameters')
            ->addOption('sleep', 'S', InputOption::VALUE_REQUIRED, 'Set sleep time', 1)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return 1;
        }

        $helper = $this->getHelper('question');

        if (!$applicationId = $input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $applicationId = $helper->ask($input, $output, $applicationQuestion);
        }

        if (!$scanId = $input->getOption('scan')) {
            $scanQuestion = new Question('Please enter a scan id: ');
            $scanId = $helper->ask($input, $output, $scanQuestion);
        }

        if (!$typeId = $input->getOption('type')) {
            $typeQuestion = new Question('Please enter a review type id: ');
            $typeId = $helper->ask($input, $output, $typeQuestion);
        }

        // Add (optional) query parameters.
        $queryParams = [];
        foreach ($input->getOption('parameter') as $parameter) {
            $parameterSplit = explode('=', $parameter, 2);

            if (isset($queryParams[$parameterSplit[0]])) {
                $output->writeln('<error>Failure:</error> Query parameter collision of "' . $parameterSplit[0] . '"');
                return 1;
            }

            if (count($parameterSplit) === 1) {
                $queryParams[$parameterSplit[0]] = 1;
            } else {
                $queryParams[$parameterSplit[0]] = $parameterSplit[1];
            }
        }

        /** @var \RIPS\ConnectorBundle\Services\Application\Scan\IssueService $issueService */
        $issueService = $this->getContainer()->get('rips_connector.application.scan.issues');

        // There is no point in getting issues that have this review already. But when the last review is null and we
        // use it in a filter, no issues are returned. So we have to split this into two requests.
        $queryParams1 = $queryParams;
        $queryParams1['notNull[lastReviewType]'] = 1;
        $queryParams1['notEqual[lastReviewType]'] = $typeId;
        $issues = $issueService->getAll($applicationId, $scanId, $queryParams1);

        // Review type 1 is "no review", so there is no point in getting issues without review.
        if (intval($typeId) !== 1) {
            $queryParams2 = $queryParams;
            $queryParams2['null[lastReviewType]'] = 1;
            $issues = array_merge($issues, $issueService->getAll($applicationId, $scanId, $queryParams2));
        }

        /** @var \RIPS\ConnectorBundle\Services\Application\Scan\Issue\ReviewService $reviewService */
        $reviewService = $this->getContainer()->get('rips_connector.application.scan.issue.reviews');

        /** @var \RIPS\ConnectorBundle\Entities\Application\Scan\IssueEntity $issue */
        foreach ($issues as $issue) {
            $reviewInput = new ReviewBuilder([
                'type' => $typeId
            ]);
            $review = $reviewService->create($applicationId, $scanId, $issue->getId(), $reviewInput);
            $output->writeln('<info>Success:</info> Review ' . $review->getId() . ' was successfully created');

            // The API contains a flood protection. We throttle the requests a bit to avoid triggering it.
            if ($sleep = $input->getOption('sleep')) {
                sleep($sleep);
            }
        }

        return 0;
    }
}
