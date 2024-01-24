<?php

namespace App\Service;

use App\Entity\Book;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kiwilan\Ebook\Ebook;
use Kiwilan\Ebook\EbookCover;
use Kiwilan\Ebook\Tools\BookAuthor;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @phpstan-type MetadataType array{ title:string, authors: BookAuthor[], main_author: ?BookAuthor, description: ?string, publisher: ?string, publish_date: ?\DateTime, language: ?string, tags: string[], serie:?string, serie_index: ?int, cover: ?EbookCover }
 */
class BookManager
{

    public function __construct(private KernelInterface $appKernel, private BookFileSystemManager $fileSystemManager, private EntityManagerInterface $entityManager, private BookRepository $bookRepository)
    {
    }

    /**
     * @throws \Exception
     */
    public function createBook(\SplFileInfo $file): Book
    {
        $book = new Book();

        $extractedMetadata = $this->extractEbookMetadata($file);
        $book->setTitle($extractedMetadata['title']);
        $book->setChecksum($this->fileSystemManager->getFileChecksum($file));
        if (null !== $extractedMetadata['main_author'] && null !== $extractedMetadata['main_author']->getName()) {
            $book->addAuthor($extractedMetadata['main_author']->getName());
        }

        foreach ($extractedMetadata['authors'] as $author) {
            if ($author->getName() !== null) {
                $book->addAuthor($author->getName());
            }
        }

        if (0 === count($book->getAuthors())) {
            $book->addAuthor('unknown');
        }

        $book->setSummary($extractedMetadata['description']);
        if (null !== $extractedMetadata['serie']) {
            $book->setSerie($extractedMetadata['serie']);
            $book->setSerieIndex($extractedMetadata['serie_index']);
        }
        $book->setPublisher($extractedMetadata['publisher']);
        if (2 === strlen($extractedMetadata['language'] ?? '')) {
            $book->setLanguage($extractedMetadata['language']);
        }

        $book->setExtension($file->getExtension());
        $book->setTags($extractedMetadata['tags']);

        $book->setBookPath('');
        $book->setBookFilename('');

        $extractedMetadata = null;

        return $this->updateBookLocation($book, $file);
    }

    public function updateBookLocation(Book $book, \SplFileInfo $file): Book
    {
        $path = $this->fileSystemManager->getFolderName($file);
        if ($path !== $book->getBookPath()) {
            $book->setBookPath($path);
        }
        if ($file->getFilename() !== $book->getBookFilename()) {
            $book->setBookFilename($file->getFilename());
        }

        return $book;
    }

    /**
     * @return MetadataType
     *
     * @throws \Exception
     */
    public function extractEbookMetadata(\SplFileInfo $file): array
    {
        try {
            if (!Ebook::isValid($file->getRealPath())) {
                throw new \RuntimeException('Could not read ebook'.$file->getRealPath());
            }

            $ebook = Ebook::read($file->getRealPath());
            if (null === $ebook) {
                throw new \RuntimeException('Could not read ebook');
            }
        } catch (\Exception $e) {
            $ebook = null;

            return [
                'title' => $file->getFilename(),
                'authors' => [new BookAuthor('unknown')], // BookAuthor[] (`name`: string, `role`: string)
                'main_author' => new BookAuthor('unknown'), // ?BookAuthor => First BookAuthor (`name`: string, `role`: string)
                'description' => null, // ?string
                'publisher' => null, // ?string
                'publish_date' => null, // ?DateTime
                'language' => null, // ?string
                'tags' => [], // string[] => `subject` in EPUB, `keywords` in PDF, `genres` in CBA
                'serie' => null, // ?string => `calibre:series` in EPUB, `series` in CBA
                'serie_index' => null, // ?int => `calibre:series_index` in EPUB, `number` in CBA
                'cover' => null, //  ?EbookCover => cover of book
            ];
        }

        $data = [
            'title' => $ebook->getTitle() ?? $file->getBasename('.'.$file->getExtension()), // string
            'authors' => $ebook->getAuthors(), // BookAuthor[] (`name`: string, `role`: string)
            'main_author' => $ebook->getAuthorMain(), // ?BookAuthor => First BookAuthor (`name`: string, `role`: string)
            'description' => $ebook->getDescription(), // ?string
            'publisher' => $ebook->getPublisher(), // ?string
            'publish_date' => $ebook->getPublishDate(), // ?DateTime
            'language' => $ebook->getLanguage(), // ?string
            'tags' => $ebook->getTags(), // string[] => `subject` in EPUB, `keywords` in PDF, `genres` in CBA
            'serie' => $ebook->getSeries(), // ?string => `calibre:series` in EPUB, `series` in CBA
            'serie_index' => $ebook->getVolume(), // ?int => `calibre:series_index` in EPUB, `number` in CBA
            'cover' => $ebook->getCover(), //  ?EbookCover => cover of book
        ];
        $ebook = null;

        return $data;
    }

    public function consumeBooks(array $files, ?InputInterface $input=null, ?OutputInterface $output=null):void
    {
        if($output===null) {
            $output = new NullOutput();
        }
        if($input===null) {
            $input = new StringInput('');
        }
        $io = new SymfonyStyle($input, $output);

        $progressBar = new ProgressBar($output, count($files));

        $progressBar->setFormat('debug');
        $progressBar->start();
        foreach ($files as $file) {
            $progressBar->advance();
            try {
                $book = $this->consumeBook($file);
                $progressBar->setMessage($file->getFilename());

                if ($book===null) {
                    continue;
                }

                $this->entityManager->persist($book);
                $this->entityManager->flush();

            } catch (\Exception $e) {
                $output->error('died during process of '.$file->getRealPath());
                $output->error($e->getMessage());
                throw $e;
            }
            $book = null;
            gc_collect_cycles();
        }
        $progressBar->finish();
    }

    public function consumeBook(\SplFileInfo $file):Book
    {
        $book = $this->bookRepository->findOneBy(
            [
                'bookPath' => $this->fileSystemManager->getFolderName($file),
                'bookFilename' => $file->getFilename(),
            ]
        );

        if (null !== $book) {
            return $book;
        }

        $checksum = $this->fileSystemManager->getFileChecksum($file);
        $book = $this->bookRepository->findOneBy(['checksum' => $checksum]);

        if (null === $book) {
            $book = $this->createBook($file);
        } else {
            $book = $this->updateBookLocation($book, $file);
        }

        return $book;
    }
}
