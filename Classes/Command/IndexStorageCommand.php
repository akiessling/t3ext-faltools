<?php
declare(strict_types=1);

namespace AndreasKiessling\Faltools\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'faltools:index-storage',
    description: 'Index TYPO3 FAL storage changes from the CLI.',
)]
final class IndexStorageCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'storageUid',
                InputArgument::OPTIONAL,
                'UID of the sys_file_storage record to index.'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Index all configured file storages.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageUid = $input->getArgument('storageUid');
        $indexAllStorages = (bool)$input->getOption('all');

        if (!$indexAllStorages && ($storageUid === null || $storageUid === '')) {
            $output->writeln('<error>Please provide a storage UID or use --all.</error>');
            return Command::INVALID;
        }

        if ($indexAllStorages && $storageUid !== null && $storageUid !== '') {
            $output->writeln('<error>Please use either a storage UID or --all, not both.</error>');
            return Command::INVALID;
        }

        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storages = $indexAllStorages
            ? $storageRepository->findAll()
            : [$this->resolveStorage($storageRepository, (string)$storageUid)];

        foreach ($storages as $storage) {
            $this->indexStorage($storage, $output);
        }

        $output->writeln(sprintf('<info>Indexed %d storage%s.</info>', count($storages), count($storages) === 1 ? '' : 's'));

        return Command::SUCCESS;
    }

    private function resolveStorage(StorageRepository $storageRepository, string $storageUid): ResourceStorage
    {
        if (!ctype_digit($storageUid) || (int)$storageUid <= 0) {
            throw new \InvalidArgumentException('The storage UID must be a positive integer.', 1761810001);
        }

        $storage = $storageRepository->findByUid((int)$storageUid);
        if ($storage === null) {
            throw new \RuntimeException(sprintf('Storage with UID %d does not exist.', (int)$storageUid), 1761810002);
        }

        return $storage;
    }

    private function indexStorage(ResourceStorage $storage, OutputInterface $output): void
    {
        $output->writeln(sprintf('Indexing storage %d...', $storage->getUid()));

        $currentEvaluatePermissionsValue = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            GeneralUtility::makeInstance(Indexer::class, $storage)->processChangesInStorages();
        } finally {
            $storage->setEvaluatePermissions($currentEvaluatePermissionsValue);
        }
    }
}
