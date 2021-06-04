<?php


namespace Golly\Elastic\Queries\Compound;

use Golly\Elastic\Contracts\QueryInterface;
use Golly\Elastic\Queries\Query;

/**
 * Class BoolQuery
 * @package Golly\Elastic\Queries\Compound
 */
class BoolQuery extends Query
{
    public const MUST = 'must';  // 与 AND 等价。
    public const MUST_NOT = 'must_not'; // 与 NOT 等价
    public const SHOULD = 'should'; // 与 OR 等价
    public const FILTER = 'filter';

    /**
     * @var array
     */
    public array $wheres = [];


    /**
     * BooleanQuery constructor.
     * @param array $params
     */
    public function __construct(array $params = [])
    {
        $this->setParams($params);
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return 'bool';
    }

    /**
     * @return array
     */
    public function getTypeValue(): array
    {
        $output = [];
        foreach ($this->wheres as $type => $queries) {
            /** @var QueryInterface $query */
            foreach ($queries as $query) {
                $output[$type][] = $query->toArray();
            }
        }

        return $this->merge($output);
    }

    /**
     * @param QueryInterface $query
     * @param string $type
     * @return $this
     */
    public function addQuery(QueryInterface $query, string $type): self
    {
        if ($type == self::SHOULD) {
            $this->addParam('minimum_should_match', 1);
        }
        $this->wheres[$type][] = $query;

        return $this;
    }
}
