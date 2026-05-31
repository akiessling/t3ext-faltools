<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Repository;

use AndreasKiessling\Faltools\Dto\MissingFile;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final readonly class MissingFileRepository
{
    private const SYS_FILE = 'sys_file';
    private const SYS_REFINDEX = 'sys_refindex';
    private const SYS_FILE_METADATA = 'sys_file_metadata';

    public function __construct(
        private ConnectionPool $connectionPool,
        private StorageRepository $storageRepository,
        private ReferenceIndex $referenceIndex,
        private ResourceFactory $resourceFactory,
    ) {}

    public function createMissingFilesQueryBuilder(?int $storageUid = null, string $path = ''): QueryBuilder
    {
        $queryBuilder = $this->createMissingFileBaseQueryBuilder();
        $queryBuilder
            ->select(
                'uid',
                'storage',
                'identifier',
                'extension',
                'mime_type',
                'name',
                'size',
                'creation_date',
                'modification_date',
                'last_indexed'
            )
            ->orderBy('storage', 'ASC')
            ->addOrderBy('identifier', 'ASC');

        $this->addStorageConstraint($queryBuilder, $storageUid);
        $this->addPathConstraint($queryBuilder, $path);

        return $queryBuilder;
    }

    public function findMissingFileByUid(int $uid): ?MissingFile
    {
        $queryBuilder = $this->createMissingFilesQueryBuilder();
        $queryBuilder
            ->andWhere(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->setMaxResults(1);

        $row = $queryBuilder->executeQuery()->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowsToMissingFiles([$row])[0] ?? null;
    }

    /**
     * @return list<array{uid:int,storage:int,name:string,sha1:string,storage_path:string}>
     */
    public function findMissingFileExportRows(?int $storageUid = null, string $path = ''): array
    {
        $queryBuilder = $this->createMissingFileBaseQueryBuilder();
        $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name', 'sha1')
            ->orderBy('storage', 'ASC')
            ->addOrderBy('identifier', 'ASC');

        $this->addStorageConstraint($queryBuilder, $storageUid);
        $this->addPathConstraint($queryBuilder, $path);

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $storagePathPrefixes = [];
        $exportRows = [];
        foreach ($rows as $row) {
            $resolvedStorageUid = (int)$row['storage'];
            if (!array_key_exists($resolvedStorageUid, $storagePathPrefixes)) {
                $storagePathPrefixes[$resolvedStorageUid] = $this->resolveStoragePathPrefix($resolvedStorageUid);
            }
            $identifier = (string)$row['identifier'];
            $exportRows[] = [
                'uid' => (int)$row['uid'],
                'storage' => $resolvedStorageUid,
                'name' => (string)$row['name'],
                'sha1' => (string)$row['sha1'],
                'storage_path' => $this->buildStoragePath($storagePathPrefixes[$resolvedStorageUid], $identifier),
            ];
        }

        return $exportRows;
    }

    /**
     * @return list<int>
     */
    public function findMissingFileUids(?int $storageUid = null, string $path = ''): array
    {
        $queryBuilder = $this->createMissingFileBaseQueryBuilder();
        $queryBuilder
            ->select('uid')
            ->orderBy('uid', 'ASC');

        $this->addStorageConstraint($queryBuilder, $storageUid);
        $this->addPathConstraint($queryBuilder, $path);

        return array_map(
            static fn($uid): int => (int)$uid,
            $queryBuilder->executeQuery()->fetchFirstColumn()
        );
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     * @return list<MissingFile>
     */
    public function mapRowsToMissingFiles(iterable $rows): array
    {
        $storageNames = $this->getStorageNames();
        $files = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $storage = (int)$row['storage'];
            $files[] = new MissingFile(
                $uid,
                $storage,
                $storageNames[$storage] ?? ('Storage ' . $storage),
                (string)$row['identifier'],
                (string)$row['name'],
                (string)$row['extension'],
                (string)$row['mime_type'],
                (int)$row['size'],
                (int)$row['creation_date'],
                (int)$row['modification_date'],
                (int)$row['last_indexed'],
                $this->countReferencesFromRefIndex($uid),
                $this->resolvePublicUrl($uid, $row)
            );
        }

        return $files;
    }

    public function countReferencedMissingFiles(?int $storageUid = null, string $path = ''): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SYS_FILE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->selectLiteral('COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('f.uid') . ')')
            ->from(self::SYS_FILE, 'f')
            ->innerJoin('f', self::SYS_REFINDEX, 'r', $this->buildRefIndexJoinCondition($queryBuilder, 'f.uid'))
            ->where(
                $queryBuilder->expr()->eq('f.missing', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            );

        $this->addStorageConstraint($queryBuilder, $storageUid, 'f');
        $this->addPathConstraint($queryBuilder, $path, 'f');

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    /**
     * @return list<array{storage: int, storage_name: string, identifier: string, reference_count: int}>
     */
    public function findMissingFileTreeRows(?int $storageUid = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SYS_FILE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('f.uid', 'f.storage', 'f.identifier')
            ->addSelectLiteral('COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('r.hash') . ') AS reference_count')
            ->from(self::SYS_FILE, 'f')
            ->leftJoin('f', self::SYS_REFINDEX, 'r', $this->buildRefIndexJoinCondition($queryBuilder, 'f.uid'))
            ->where(
                $queryBuilder->expr()->eq('f.missing', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            )
            ->groupBy('f.uid', 'f.storage', 'f.identifier')
            ->orderBy('f.storage', 'ASC')
            ->addOrderBy('f.identifier', 'ASC');

        $this->addStorageConstraint($queryBuilder, $storageUid, 'f');

        $storageNames = $this->getStorageNames();
        $rows = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $storage = (int)$row['storage'];
            $rows[] = [
                'storage' => $storage,
                'storage_name' => $storageNames[$storage] ?? ('Storage ' . $storage),
                'identifier' => (string)$row['identifier'],
                'reference_count' => (int)$row['reference_count'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function getStorageNames(): array
    {
        $storageNames = [];
        foreach ($this->storageRepository->findAll() as $storage) {
            if (
                !$storage instanceof ResourceStorage
                || $storage->isFallbackStorage()
                || !$this->isStorageAccessibleForCurrentUser($storage)
            ) {
                continue;
            }
            $storageNames[$storage->getUid()] = $storage->getName();
        }
        return $storageNames;
    }

    private function isStorageAccessibleForCurrentUser(ResourceStorage $storage): bool
    {
        if ($storage->getFileMounts() !== []) {
            return true;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        return $backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin();
    }

    private function countReferencesFromRefIndex(int $fileUid): int
    {
        // ReferenceIndex::getNumberOfReferencedRecords() also counts sys_file_metadata self-relations.
        // For this module we show only "real" usage references, so metadata references are subtracted here.
        // SQL-based aggregate queries in this repository already exclude metadata in their join condition.
        return max(
            0,
            $this->referenceIndex->getNumberOfReferencedRecords('sys_file', $fileUid) - $this->countMetadataReferencesFromRefIndex($fileUid)
        );
    }

    private function countMetadataReferencesFromRefIndex(int $fileUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SYS_REFINDEX);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('*')
            ->from(self::SYS_REFINDEX)
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter(self::SYS_FILE)),
                $queryBuilder->expr()->eq('ref_uid', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter(self::SYS_FILE_METADATA))
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function addStorageConstraint(QueryBuilder $queryBuilder, ?int $storageUid, string $tableAlias = ''): void
    {
        if ($storageUid === null || $storageUid <= 0) {
            return;
        }
        $field = $tableAlias !== '' ? $tableAlias . '.storage' : 'storage';

        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($storageUid, Connection::PARAM_INT))
        );
    }

    private function addPathConstraint(QueryBuilder $queryBuilder, string $path, string $tableAlias = ''): void
    {
        if ($path === '') {
            return;
        }
        $field = $tableAlias !== '' ? $tableAlias . '.identifier' : 'identifier';

        $queryBuilder->andWhere(
            $queryBuilder->expr()->like(
                $field,
                $queryBuilder->createNamedParameter($this->escapeLikePath($queryBuilder, $path) . '%')
            )
        );
    }

    private function createMissingFileBaseQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SYS_FILE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->from(self::SYS_FILE)
            ->where(
                $queryBuilder->expr()->eq('missing', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
            );

        return $queryBuilder;
    }

    private function buildRefIndexJoinCondition(QueryBuilder $queryBuilder, string $uidField): string
    {
        return (string)$queryBuilder->expr()->and(
            $queryBuilder->expr()->eq('r.ref_table', $queryBuilder->createNamedParameter(self::SYS_FILE)),
            $queryBuilder->expr()->eq('r.ref_uid', $queryBuilder->quoteIdentifier($uidField)),
            // Metadata self-relations are not user-facing file usages in this module.
            $queryBuilder->expr()->neq('r.tablename', $queryBuilder->createNamedParameter(self::SYS_FILE_METADATA))
        );
    }

    private function escapeLikePath(QueryBuilder $queryBuilder, string $path): string
    {
        return $queryBuilder->escapeLikeWildcards($this->normalizePath($path));
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/') . '/';
        return $path === '//' ? '/' : $path;
    }

    private function resolveStoragePathPrefix(int $storageUid): string
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage instanceof ResourceStorage) {
            return '';
        }
        $configuration = $storage->getConfiguration();
        if (isset($configuration['basePath']) && is_string($configuration['basePath']) && trim($configuration['basePath']) !== '') {
            return trim((string)$configuration['basePath'], '/');
        }

        return trim((string)$storage->getName(), '/');
    }

    private function buildStoragePath(string $storagePrefix, string $identifier): string
    {
        $normalizedIdentifier = ltrim($identifier, '/');
        if ($storagePrefix === '') {
            return $normalizedIdentifier;
        }
        if ($normalizedIdentifier === '') {
            return $storagePrefix;
        }

        return $storagePrefix . '/' . $normalizedIdentifier;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolvePublicUrl(int $uid, array $row): ?string
    {
        try {
            $fileObject = $this->resourceFactory->getFileObject($uid, $row);
            $publicUrl = $fileObject->getPublicUrl();
            return is_string($publicUrl) && $publicUrl !== '' ? $publicUrl : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
