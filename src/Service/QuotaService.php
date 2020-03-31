<?php

namespace App\Service;

use RIPS\ConnectorBundle\Entities\QuotaEntity;
use RIPS\ConnectorBundle\InputBuilders\FilterBuilder;

class QuotaService
{
    /** @var \RIPS\ConnectorBundle\Services\QuotaService */
    private $quotaService;

    /**
     * @param \RIPS\ConnectorBundle\Services\QuotaService $quotaService
     */
    public function setQuotaService(\RIPS\ConnectorBundle\Services\QuotaService $quotaService): void
    {
        $this->quotaService = $quotaService;
    }

    /**
     * Try to find the first quota that expires and supports the given language.
     *
     * @param string|int $language
     * @return QuotaEntity|null
     * @throws \Exception
     */
    public function getQuotaForLanguage($language): ?QuotaEntity
    {
        $now = new \DateTime();

        $filterBuilder = new FilterBuilder();
        $condition = $filterBuilder->and(
            $filterBuilder->lessThan('validFrom', $now->format(DATE_ISO8601)),
            $filterBuilder->greaterThan('validUntil', $now->format(DATE_ISO8601))
        );

        $quotas = $this->quotaService->getAll([
            'filter'  => $filterBuilder->getFilterString($condition),
            'orderBy' => json_encode(['validUntil' => 'asc'])
        ])->getQuotas();

        foreach ($quotas as $quota) {
            if ($quota->getMaxApplications() && $quota->getCurrentApplication() >= $quota->getMaxApplications()) {
                continue;
            }
            foreach ($quota->getLanguages() as $quotaLanguage) {
                $idMatch = $quotaLanguage->getId() === (int)$language;
                $nameMatch = strtolower($quotaLanguage->getName()) === strtolower($language);
                if ($idMatch || $nameMatch) {
                    return $quota;
                }
            }
        }

        return null;
    }
}
