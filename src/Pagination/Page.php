<?php

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * One page of an admin list. It iterates and counts like the list it
 * replaces, so templates loop over it unchanged; total and pageCount drive
 * the shared admin/_pagination.html.twig links.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final readonly class Page implements \IteratorAggregate, \Countable
{
    public const PER_PAGE = 25;

    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $pageCount,
        public int $total,
    ) {
    }

    /**
     * @return self<mixed>
     */
    public static function of(QueryBuilder $query, int $page, int $perPage = self::PER_PAGE): self
    {
        $page = max(1, $page);
        $paginator = new Paginator($query->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery(), fetchJoinCollection: false);
        $total = \count($paginator);
        $pageCount = max(1, (int) ceil($total / $perPage));
        if ($page > $pageCount) {
            return self::of($query, $pageCount, $perPage);
        }

        return new self(iterator_to_array($paginator, false), $page, $pageCount, $total);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
