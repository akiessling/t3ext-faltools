<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Pagination;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Pagination\AbstractPaginator;

/**
 * TYPO3 v13 compatibility copy for TYPO3 v14.2's core QueryBuilderPaginator.
 *
 * @todo Remove this class and use TYPO3\CMS\Core\Pagination\QueryBuilderPaginator after upgrading to TYPO3 >= 14.2.
 */
final class QueryBuilderPaginator extends AbstractPaginator
{
    private array $paginatedItems = [];
    private ?int $totalAmountOfItems = null;

    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
    ) {
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);
        $this->updateInternalState();
    }

    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        $queryBuilder = clone $this->queryBuilder;
        $queryBuilder
            ->setFirstResult($offset)
            ->setMaxResults($itemsPerPage);

        $this->paginatedItems = $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function getTotalAmountOfItems(): int
    {
        if ($this->totalAmountOfItems !== null) {
            return $this->totalAmountOfItems;
        }

        $queryBuilder = clone $this->queryBuilder;
        $queryBuilder
            ->resetOrderBy()
            ->setFirstResult(0)
            ->setMaxResults(null);

        $connection = $queryBuilder->getConnection();
        $countSql = 'WITH paginated_query AS (' . $queryBuilder->getSQL() . ') SELECT COUNT(*) FROM paginated_query';

        $this->totalAmountOfItems = (int)$connection
            ->executeQuery($countSql, $queryBuilder->getParameters(), $queryBuilder->getParameterTypes())
            ->fetchOne();

        return $this->totalAmountOfItems;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
