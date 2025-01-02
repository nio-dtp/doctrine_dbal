<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query;

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_merge;
use function count;
use function implode;
use function sprintf;

class QueryBuildersAggregator
{
    private static self|null $instance = null;

    /**
     * Set to true each time a query is executed,
     * resetting the collection when a new QueryBuilder is created.
     */
    private bool $toReset = false;

    /**
     * Aggregate of QueryBuilder used for the query.
     *
     * @var QueryBuilder[] $queryBuilders
     */
    private array $queryBuilders = [];

    /**
     * The counter of bound parameters used by the query builders.
     *
     * @var int<0, max>
     */
    private int $boundCounter = 0;

    public static function create(): self
    {
        if (self::$instance === null) {
            self::$instance = new QueryBuildersAggregator();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function toReset(): void
    {
        $this->toReset = true;
    }

    public function register(QueryBuilder $queryBuilder): void
    {
        if ($this->toReset) {
            $this->queryBuilders = [];
            $this->boundCounter  = 0;
            $this->toReset       = false;
        }

        $this->queryBuilders[] = $queryBuilder;
    }

    /**
     * @return list<mixed>|array<string, mixed>
     *
     * @throws QueryException
     */
    public function buildParametersAndTypes(): array
    {
        $parameters = [];
        $types      = [];
        foreach ($this->queryBuilders as $queryBuilder) {
            $this->rejectDuplicatedParameterNames($parameters, $queryBuilder->getParameters());
            $parameters = array_merge($parameters, $queryBuilder->getParameters());
            $types      = array_merge($types, $queryBuilder->getParameterTypes());
        }

        return [$parameters, $types];
    }

    /**
     * Guards against duplicated parameter names.
     *
     * @param list<mixed>|array<string, mixed> $params
     * @param list<mixed>|array<string, mixed> $paramsToMerge
     *
     * @throws QueryException
     */
    private function rejectDuplicatedParameterNames(array $params, array $paramsToMerge): void
    {
        if (count($params) === 0 || count($paramsToMerge) === 0) {
            return;
        }

        $paramKeys    = array_filter(array_keys($params), 'is_string');
        $cteParamKeys = array_filter(array_keys($paramsToMerge), 'is_string');
        $duplicated   = array_intersect($paramKeys, $cteParamKeys);
        if (count($duplicated) > 0) {
            throw new QueryException(sprintf(
                'Found duplicated parameter in query. The duplicated parameter names are: "%s".',
                implode(', ', $duplicated),
            ));
        }
    }

    public function getBoundCounter(): int
    {
        return $this->boundCounter;
    }

    public function incrementBoundCounter(): void
    {
        $this->boundCounter++;
    }
}
