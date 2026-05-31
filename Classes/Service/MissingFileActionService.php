<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Service;

use AndreasKiessling\Faltools\Dto\MissingFileDeletionResult;
use AndreasKiessling\Faltools\Dto\MissingFileBulkDeletionResult;
use AndreasKiessling\Faltools\Dto\MissingFileRestoreResult;
use AndreasKiessling\Faltools\Exception\MissingFileNotFoundException;
use AndreasKiessling\Faltools\Exception\MissingFilePermissionDeniedException;
use AndreasKiessling\Faltools\Exception\MissingFileReferencedException;
use AndreasKiessling\Faltools\Exception\MissingFileRestoreException;
use AndreasKiessling\Faltools\Repository\MissingFileRepository;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\FileIndexRepository;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final readonly class MissingFileActionService
{
    public function __construct(
        private MissingFileRepository $missingFileRepository,
        private ConnectionPool $connectionPool,
        private FileIndexRepository $fileIndexRepository,
        private ReferenceIndex $referenceIndex,
        private StorageRepository $storageRepository,
        private ResourceFactory $resourceFactory,
        private ProcessedFileRepository $processedFileRepository,
    ) {}

    public function deleteMissingFile(int $uid, bool $forceReferences): MissingFileDeletionResult
    {
        $file = $this->missingFileRepository->findMissingFileByUid($uid);
        if ($file === null) {
            throw new MissingFileNotFoundException('Missing file record was not found.', 1780483201);
        }
        $this->assertMissingFileAccess($file->uid, $file->storage, $file->identifier, 'delete');
        if ($file->hasReferences() && !$forceReferences) {
            throw new MissingFileReferencedException('Missing file record still has references.', 1780483202);
        }

        $removedReferenceUids = $forceReferences ? $this->deleteFileReferences($file->uid) : [];
        $removedMetadataUids = $this->deleteMetadataRecords($file->uid);
        $removedProcessedFiles = $this->deleteProcessedFiles($file->uid);
        $this->fileIndexRepository->remove($file->uid);

        foreach ($removedReferenceUids as $referenceUid) {
            $this->referenceIndex->updateRefIndexTable('sys_file_reference', $referenceUid);
        }
        foreach ($removedMetadataUids as $metadataUid) {
            $this->referenceIndex->updateRefIndexTable('sys_file_metadata', $metadataUid);
        }

        return new MissingFileDeletionResult(
            $file,
            count($removedReferenceUids),
            count($removedMetadataUids),
            $removedProcessedFiles,
        );
    }

    public function restoreMissingFile(int $uid, UploadedFileInterface $uploadedFile): MissingFileRestoreResult
    {
        $file = $this->missingFileRepository->findMissingFileByUid($uid);
        if ($file === null) {
            throw new MissingFileNotFoundException('Missing file record was not found.', 1780483203);
        }
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new MissingFileRestoreException('Uploaded file is not valid.', 1780483204);
        }

        $storage = $this->assertMissingFileAccess($file->uid, $file->storage, $file->identifier, 'write');
        $indexer = GeneralUtility::makeInstance(Indexer::class, $storage);
        if ($storage->hasFile($file->identifier)) {
            $existingFile = $storage->getFile($file->identifier);
            $indexer->updateIndexEntry($existingFile);
            return new MissingFileRestoreResult(
                $file,
                $existingFile->getIdentifier(),
                true
            );
        }

        $targetFolder = $this->ensureFolderPath($storage->getRootLevelFolder(), $file->getFolderIdentifier());

        try {
            $restoredFile = $storage->addUploadedFile(
                $uploadedFile,
                $targetFolder,
                $file->name,
                DuplicationBehavior::CANCEL
            );
        } catch (ExistingTargetFileNameException $exception) {
            throw new MissingFileRestoreException('A file already exists at the target identifier.', 1780483205, $exception);
        }
        $indexer->updateIndexEntry($restoredFile);

        return new MissingFileRestoreResult(
            $file,
            $restoredFile->getIdentifier(),
            false
        );
    }

    /**
     * @param list<int> $uids
     */
    public function deleteMissingFiles(array $uids, bool $forceReferences): MissingFileBulkDeletionResult
    {
        $removedFiles = 0;
        $skippedFiles = 0;
        $failedFiles = 0;
        $removedReferences = 0;
        $removedMetadataRecords = 0;
        $removedProcessedFiles = 0;

        foreach (array_values(array_unique(array_filter($uids, static fn(int $uid): bool => $uid > 0))) as $uid) {
            try {
                $result = $this->deleteMissingFile($uid, $forceReferences);
                $removedFiles++;
                $removedReferences += $result->removedReferences;
                $removedMetadataRecords += $result->removedMetadataRecords;
                $removedProcessedFiles += $result->removedProcessedFiles;
            } catch (MissingFileNotFoundException) {
                // Concurrent cleanup is expected in bulk operations: a record may already be gone.
                $skippedFiles++;
                continue;
            } catch (\Throwable) {
                $failedFiles++;
            }
        }

        return new MissingFileBulkDeletionResult(
            $removedFiles,
            $skippedFiles,
            $failedFiles,
            $removedReferences,
            $removedMetadataRecords,
            $removedProcessedFiles,
        );
    }

    /**
     * @return list<int>
     */
    private function deleteFileReferences(int $fileUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $queryBuilder = $connection->createQueryBuilder();
        $referenceUids = array_map(
            static fn($uid): int => (int)$uid,
            $queryBuilder
                ->select('uid')
                ->from('sys_file_reference')
                ->where(
                    $queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchFirstColumn()
        );

        if ($referenceUids !== []) {
            $connection->delete('sys_file_reference', ['uid_local' => $fileUid], [Connection::PARAM_INT]);
        }

        return $referenceUids;
    }

    /**
     * @return list<int>
     */
    private function deleteMetadataRecords(int $fileUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file_metadata');
        $queryBuilder = $connection->createQueryBuilder();
        $metadataUids = array_map(
            static fn($uid): int => (int)$uid,
            $queryBuilder
                ->select('uid')
                ->from('sys_file_metadata')
                ->where(
                    $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchFirstColumn()
        );

        if ($metadataUids === []) {
            return [];
        }

        $categoryConnection = $this->connectionPool->getConnectionForTable('sys_category_record_mm');
        $categoryQueryBuilder = $categoryConnection->createQueryBuilder();
        $categoryQueryBuilder
            ->delete('sys_category_record_mm')
            ->where(
                $categoryQueryBuilder->expr()->eq(
                    'tablenames',
                    $categoryQueryBuilder->createNamedParameter('sys_file_metadata')
                ),
                $categoryQueryBuilder->expr()->in(
                    'uid_foreign',
                    $categoryQueryBuilder->createNamedParameter($metadataUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeStatement();
        $connection->delete('sys_file_metadata', ['file' => $fileUid], [Connection::PARAM_INT]);

        return $metadataUids;
    }

    private function deleteProcessedFiles(int $fileUid): int
    {
        $removedProcessedFiles = 0;
        try {
            $originalFile = $this->resourceFactory->getFileObject($fileUid);
            foreach ($this->processedFileRepository->findAllByOriginalFile($originalFile) as $processedFile) {
                $storage = $processedFile->getStorage();
                $currentEvaluatePermissionsValue = $storage->getEvaluatePermissions();
                $storage->setEvaluatePermissions(false);
                try {
                    if ($processedFile->exists()) {
                        $processedFile->delete(true);
                    }
                } finally {
                    $storage->setEvaluatePermissions($currentEvaluatePermissionsValue);
                }
                $removedProcessedFiles += $this->connectionPool
                    ->getConnectionForTable('sys_file_processedfile')
                    ->delete('sys_file_processedfile', ['uid' => $processedFile->getUid()], [Connection::PARAM_INT]);
            }
        } catch (\Throwable) {
            // If the original file object cannot be loaded, fall back to DB cleanup only.
        }

        $removedProcessedFiles += $this->connectionPool
            ->getConnectionForTable('sys_file_processedfile')
            ->delete('sys_file_processedfile', ['original' => $fileUid], [Connection::PARAM_INT]);

        return $removedProcessedFiles;
    }

    private function ensureFolderPath(Folder $rootFolder, string $folderIdentifier): Folder
    {
        if ($folderIdentifier === '/' || $folderIdentifier === '') {
            return $rootFolder;
        }

        $currentFolder = $rootFolder;
        $pathParts = array_values(array_filter(explode('/', trim($folderIdentifier, '/')), static fn(string $part): bool => $part !== ''));
        foreach ($pathParts as $pathPart) {
            if ($currentFolder->hasFolder($pathPart)) {
                $currentFolder = $currentFolder->getSubfolder($pathPart);
                continue;
            }
            $currentFolder = $currentFolder->createFolder($pathPart);
        }

        return $currentFolder;
    }

    private function assertMissingFileAccess(int $uid, int $storageUid, string $identifier, string $action): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null) {
            throw new MissingFileRestoreException('Storage for missing file was not found.', 1780483206);
        }
        if (!$storage->checkUserActionPermission($action, 'File')) {
            throw new MissingFilePermissionDeniedException('You do not have permissions for this action.', 1780483207);
        }
        try {
            $fileObject = $this->resourceFactory->getFileObject($uid);
        } catch (\Throwable) {
            $fileObject = $storage->getFile($identifier);
        }
        if (!$storage->isWithinFileMountBoundaries($fileObject, true)) {
            throw new MissingFilePermissionDeniedException('You do not have file mount access to this file.', 1780483208);
        }
        if ($action === 'write' && !$storage->isWritable()) {
            throw new MissingFilePermissionDeniedException('Storage is not writable.', 1780483209);
        }

        return $storage;
    }
}
