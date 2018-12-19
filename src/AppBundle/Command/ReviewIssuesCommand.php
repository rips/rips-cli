<?php

namespace AppBundle\Command;

use AppBundle\Service\RequestService;
use PHP_CodeSniffer\Filters\Filter;
use RIPS\ConnectorBundle\InputBuilders\FilterBuilder;
use RIPS\ConnectorBundle\Services\Application\Scan\Issue\ReviewService;
use RIPS\ConnectorBundle\Services\Application\Scan\IssueService;
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

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     */
    public function interact(InputInterface $input, OutputInterface $output)
    {
        $loginCommand = $this->getApplication()->find('rips:login');
        if ($loginCommand->run(new ArrayInput(['--config' => true]), $output)) {
            return;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('application')) {
            $applicationQuestion = new Question('Please enter an application id: ');
            $input->setOption('application', $helper->ask($input, $output, $applicationQuestion));
        }

        if (!$input->getOption('scan')) {
            $scanQuestion = new Question('Please enter a scan id: ');
            $input->setOption('scan', $helper->ask($input, $output, $scanQuestion));
        }

        if (!$input->getOption('type')) {
            $typeQuestion = new Question('Please enter a review type id: ');
            $input->setOption('type', $helper->ask($input, $output, $typeQuestion));
        }
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var int $applicationId */
        $applicationId = (int)$input->getOption('application');

        /** @var int $scanId */
        $scanId = (int)$input->getOption('scan');

        /** @var int $typeId */
        $typeId = (int)$input->getOption('type');

        /** @var RequestService $requestService */
        $requestService = $this->getContainer()->get(RequestService::class);
        $queryParams = $requestService->transformParametersForQuery($input->getOption('parameter'));

        /** @var IssueService $issueService */
        $issueService = $this->getContainer()->get(IssueService::class);

        // There is no point in getting issues that have this review already. But when the last review is null and we
        // use it in a filter, no issues are returned. So we have to split this into two requests.
        $filterBuilder = new FilterBuilder();

        $queryParams1 = $queryParams;
        $queryParams1['filter'] = $filterBuilder->getFilterString($filterBuilder->and(
            $filterBuilder->notNull('lastReviewType'),
            $filterBuilder->notEqual('lastReviewType', $typeId)
        ));
        $issues = $issueService->getAll($applicationId, $scanId, $queryParams1)->getIssues();

        // Review type 1 is "no review", so there is no point in getting issues without review.
        if (intval($typeId) !== 1) {
            $queryParams2 = $queryParams;
            $queryParams2['filter'] = $filterBuilder->getFilterString($filterBuilder->null('lastReviewType'));
            $issues = array_merge($issues, $issueService->getAll($applicationId, $scanId, $queryParams2)->getIssues());
        }

        /** @var ReviewService $reviewService */
        $reviewService = $this->getContainer()->get(ReviewService::class);

        /** @var \RIPS\ConnectorBundle\Entities\Application\Scan\IssueEntity $issue */
        foreach ($issues as $issue) {
            $reviewInput = new ReviewBuilder();
            $reviewInput->setType($typeId);
            $review = $reviewService->create($applicationId, $scanId, $issue->getId(), $reviewInput)->getReview();
            $output->writeln('<info>Success:</info> Review ' . $review->getId() . ' was successfully created');

            // The API contains a flood protection. We throttle the requests a bit to avoid triggering it.
            if ($input->getOption('sleep')) {
                sleep($input->getOption('sleep'));
            }
        }

        return 0;
    }
}
