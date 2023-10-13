<?php

namespace App\Service;

use App\Entity\Book;
use Google\Client;
use Google\Service\Books;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BookSuggestions
{
    public const EMPTY_SUGGESTIONS = [
        'image' => [],
        'title' => [],
        'authors' => [],
        'publisher' => [],
        'tags' => [],
        'summary' => [],
    ];

    public function __construct(private ParameterBagInterface $parameterBag, private SluggerInterface $slugger)
    {
    }

    /**
     * @return array<string[]>
     */
    public function getCategorySuggestions(Book $book): array
    {
        $cache = new FilesystemAdapter();

        $mainAuthor = current($book->getAuthors());

        $query = ['q' => 'title:'.$book->getTitle().' author:'.$mainAuthor, 'fields' => 'title,author_name,key,cover_i,subject'];

        $cacheKey = $this->slugger->slug('openlib-'.implode('-', $query));

        return $cache->get($cacheKey, function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(3600);

            $client = new \GuzzleHttp\Client();

            $results = $client->request('GET', 'https://openlibrary.org/search.json', ['query' => $query])->getBody()->getContents();

            $results = json_decode($results, true);

            $suggestions = self::EMPTY_SUGGESTIONS;

            if (!is_array($results) || !array_key_exists('docs', $results)) {
                return $suggestions;
            }
            foreach ($results['docs'] as $result) {
                foreach ($result['subject'] as $category) {
                    $suggestions['tags'][$category] = $category;
                }
            }

            return $suggestions;
        });
    }

    /**
     * @return array<string[]>
     */
    public function getGoogleSuggestions(Book $book): array
    {
        $key = $this->parameterBag->get('GOOGLE_API_KEY');

        $suggestions = self::EMPTY_SUGGESTIONS;

        if ('' === $key || !is_string($key)) {
            return $suggestions;
        }

        $cache = new FilesystemAdapter();

        $query = 'intitle:"'.$book->getSerie().' '.$book->getSerieIndex().' '.$book->getTitle().'" inauthor:'.implode(' ', $book->getAuthors());

        $cacheKey = $this->slugger->slug($query);

        return $cache->get($cacheKey, function (ItemInterface $item) use ($key, $book, $query, $suggestions): array {
            $item->expiresAfter(3600);

            $client = new Client();

            $client->setApplicationName('biblioteca');
            $client->setDeveloperKey($key);

            $service = new Books($client);
            $optParams = [];

            $results = $service->volumes->listVolumes($query, $optParams);

            if (0 === $results->getTotalItems()) {
                $query = 'intitle:"'.$book->getSerie().' '.$book->getSerieIndex().' '.$book->getTitle().'"';
                $results = $service->volumes->listVolumes($query, $optParams);
            }

            foreach ($results->getItems() as $result) {
                $volumeInfo = $result->getVolumeInfo();

                if (null !== $volumeInfo->getImageLinks()) {
                    $imageLinks = $volumeInfo->getImageLinks();
                    $suggestions['image'][] = $imageLinks->getExtraLarge() ??
                        $imageLinks->getLarge() ??
                        $imageLinks->getMedium() ??
                        $imageLinks->getThumbnail() ??
                        $imageLinks->getSmallThumbnail();
                }
                if (null !== $volumeInfo->getPublisher()) {
                    $suggestions['publisher'][$volumeInfo->getPublisher()] = $volumeInfo->getPublisher();
                }
                if (null !== $volumeInfo->getTitle()) {
                    $suggestions['title'][$volumeInfo->getTitle()] = $volumeInfo->getTitle();
                }
                if (null !== $volumeInfo->getDescription()) {
                    $suggestions['summary'][md5($volumeInfo->getDescription())] = $volumeInfo->getDescription();
                }
                if (null !== $volumeInfo->getAuthors()) {
                    foreach ($volumeInfo->getAuthors() as $author) {
                        $suggestions['authors'][$author] = $author;
                    }
                }if (null !== $volumeInfo->getCategories()) {
                    foreach ($volumeInfo->getCategories() as $category) {
                        $suggestions['tags'][$category] = $category;
                    }
                }
            }

            return $suggestions;
        });
    }
}
