<?php
declare(strict_types=1);

namespace Golly\Elastic;

use Closure;
use Golly\Elastic\Contracts\AggregationInterface;
use Golly\Elastic\Contracts\EndpointInterface;
use Golly\Elastic\Contracts\QueryInterface;
use Golly\Elastic\Contracts\SortInterface;
use Golly\Elastic\Endpoints\AggregationEndpoint;
use Golly\Elastic\Endpoints\HighlightEndpoint;
use Golly\Elastic\Endpoints\QueryEndpoint;
use Golly\Elastic\Endpoints\SortEndpoint;
use Golly\Elastic\Exceptions\ElasticException;
use Golly\Elastic\Hydrate\ElasticEntity;
use Golly\Elastic\Queries\Compound\BoolQuery;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Class Builder
 * @package Golly\Elastic
 */
class Builder
{

    /**
     * The index that should be returned.
     *
     * @var string
     */
    public string $index;

    /**
     * The columns that should be returned.
     *
     * @var array
     */
    public array $columns = [];

    /**
     * The number of records to skip.
     *
     * @var int
     */
    public int $offset;

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    public int $limit;

    /**
     * @var bool
     */
    public bool $explain = false;

    /**
     * @var float
     */
    public float $version;

    /**
     * @var QueryEndpoint
     */
    public QueryEndpoint $queryEndpoint;

    /**
     * @var SortEndpoint
     */
    public SortEndpoint $sortEndpoint;

    /**
     * @var AggregationEndpoint
     */
    public AggregationEndpoint $aggregationEndpoint;

    /**
     * @var HighlightEndpoint
     */
    public HighlightEndpoint $highlightEndpoint;

    /**
     * @var Engine
     */
    protected Engine $esEngine;

    /**
     * @var string[]
     */
    protected array $operators = [
        '=', '>', '>=', '<', '<=', '!=', '<>',
        'term', 'match', 'range',
        'wildcard', 'like'
    ];

    /**
     * @var string[]
     */
    protected array $params = [
        'index' => 'index',
        'columns' => '_source',
        'offset' => 'from',
        'limit' => 'size',
        'storedFields' => 'stored_fields',
        'scriptFields' => 'script_fields',
        'explain' => 'explain',
        'version' => 'version',
        'indicesBoost' => 'indices_boost',
        'minScore' => 'min_score',
        'searchAfter' => 'search_after',
        'trackTotalHits' => 'track_total_hits',
    ];

    /**
     * ElasticBuilder constructor.
     */
    public function __construct()
    {
        $this->queryEndpoint = new QueryEndpoint();
        $this->sortEndpoint = new SortEndpoint();
        $this->aggregationEndpoint = new AggregationEndpoint();
        $this->highlightEndpoint = new HighlightEndpoint();
    }

    /**
     * @param string $index
     * @return $this
     */
    public function from(string $index): self
    {
        $this->index = $index;

        return $this;
    }

    /**
     * @param array $columns
     * @return $this
     */
    public function select(array $columns = []): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @param array $columns
     * @return $this
     */
    public function addSelect(array $columns = []): self
    {
        $this->columns = array_merge($this->columns, $columns);

        return $this;
    }

    /**
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @param string $type
     * @return $this
     * @throws ElasticException
     */
    public function where(mixed $column, mixed $operator = null, mixed $value = null, string $type = 'must'): self
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $type);
        }
        if ($column instanceof QueryInterface) {
            $this->queryEndpoint->addToBoolQuery($column, $type);
            return $this;
        }

        // 预处理操作符和查询值
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );
        if ($column instanceof Closure && is_null($operator)) {
            return $this->whereBool($column, $type);
        }
        if ($this->isInvalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }
        if (is_null($value)) {
            return $this->whereNull($column, $operator !== '=');
        }
        if (in_array($operator, ['!=', '<>'])) {
            $type = 'must_not';
        }
        $this->queryEndpoint->addOpticalToBoolQuery((string)$column, $operator, $value, $type);

        return $this;
    }

    /**
     * @param string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     * @throws ElasticException
     */
    public function must(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value);
    }

    /**
     * @param mixed $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     * @throws ElasticException
     */
    public function orWhere(mixed $column, mixed $operator = null, mixed $value = null): self
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->where($column, $operator, $value, BoolQuery::SHOULD);
    }


    /**
     * @param mixed $column
     * @param mixed|null $operator
     * @param mixed|null $value
     * @return $this
     * @throws ElasticException
     */
    public function should(mixed $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->orWhere($column, $operator, $value);
    }

    /**
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }
        $this->queryEndpoint->addTermsToBoolQuery($column, $values, BoolQuery::MUST);

        return $this;
    }

    /**
     * @param string $column
     * @param array $values
     * @return $this
     */
    public function whereNotIn(string $column, array $values): self
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }
        $this->queryEndpoint->addTermsToBoolQuery($column, $values, BoolQuery::MUST_NOT);

        return $this;
    }

    /**
     * @param array $columns
     * @param false $not
     * @return $this
     */
    public function whereNull(array $columns, bool $not = false): self
    {
        $type = $not ? BoolQuery::MUST_NOT : BoolQuery::MUST;
        foreach (Arr::wrap($columns) as $column) {
            $this->queryEndpoint->addExistsToBoolQuery($column, $type);
        }

        return $this;
    }

    /**
     * @param array $columns
     * @return $this
     */
    public function whereNotNull(array $columns): self
    {
        return $this->whereNull($columns, true);
    }

    /**
     * @param string $column
     * @param int|float $min
     * @param int|float $max
     * @param string $type
     * @return $this
     */
    public function whereBetween(string $column, int|float $min, int|float $max, string $type = 'must'): self
    {
        $this->queryEndpoint->addBetweenToBoolQuery($column, $min, $max, $type);

        return $this;
    }

    /**
     * @param string $column
     * @param int|float $min
     * @param int|float $max
     * @return $this
     */
    public function shouldBetween(string $column, int|float $min, int|float $max): self
    {
        return $this->whereBetween($column, $min, $max, BoolQuery::SHOULD);
    }

    /**
     * @param string $column
     * @param int|float $min
     * @param int|float $max
     * @return $this
     */
    public function whereNotBetween(string $column, int|float $min, int|float $max): self
    {
        return $this->whereBetween($column, $min, $max, BoolQuery::MUST_NOT);
    }

    /**
     * TODO 优化逻辑，有点绕
     *
     * @param string $relation
     * @param callable $callback
     * @return $this
     */
    public function whereHas(string $relation, callable $callback): self
    {
        $query = $this->newBuilder();
        $query->setRelation($relation);
        $callback($query);
        $tWheres = $query->getBoolQueryWheres();
        foreach ($tWheres as $type => $wheres) {
            foreach ($wheres as $where) {
                $this->addToBoolQuery($where, $type);
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function newBuilder(): self
    {
        return new static();
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param callable $callback
     * @param string $type
     * @return $this
     */
    public function whereBool(callable $callback, string $type = 'must'): self
    {
        call_user_func($callback, $query = $this->newBuilder());

        return $this->addToBoolWhereQuery($query, $type);
    }

    /**
     * @param Builder $query
     * @param string $type
     * @return $this
     */
    public function addToBoolWhereQuery(Builder $query, string $type = 'must'): self
    {
        if ($bQuery = $query->getBoolQuery()) {
            $this->queryEndpoint->addToBoolQuery($bQuery, $type);
        }

        return $this;
    }

    /**
     * Return the search definition using the Query DSL
     *
     * @return BoolQuery
     */
    public function getBoolQuery(): BoolQuery
    {
        return $this->queryEndpoint->getBoolQuery();
    }

    /**
     * @return array
     */
    public function getBoolQueryWheres(): array
    {
        return $this->queryEndpoint->getBoolQuery()->wheres;
    }

    /**
     * @param QueryInterface $query
     * @param string $type
     * @return $this
     */
    public function addToBoolQuery(QueryInterface $query, string $type = 'must'): self
    {
        $this->queryEndpoint->addToBoolQuery($query, $type);

        return $this;
    }

    /**
     * @param SortInterface|string $column
     * @param string $direction
     * @return $this
     * @throws ElasticException
     */
    public function orderBy(SortInterface|string $column, string $direction = 'asc'): self
    {
        if ($column instanceof SortInterface) {
            $this->sortEndpoint->addContainer($column);
        } else {
            $direction = strtolower($direction);
            if (!in_array($direction, ['asc', 'desc'], true)) {
                throw new ElasticException('Order direction must be "asc" or "desc".');
            }
            $this->sortEndpoint->addFieldSort($column, $direction);
        }

        return $this;
    }

    /**
     * @param string $column
     * @return $this
     * @throws ElasticException
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @param string $column
     * @param array $params
     * @return $this
     */
    public function highlight(string $column, array $params = []): self
    {
        $this->highlightEndpoint->addField($column, $params);

        return $this;
    }

    /**
     * @param AggregationInterface|string $column
     * @param string $type
     * @return $this
     */
    public function aggregation(AggregationInterface|string $column, string $type): self
    {
        if ($column instanceof AggregationInterface) {
            $this->aggregationEndpoint->addContainer($column);
        } else {
            $this->aggregationEndpoint->addAggregation($column, $type);
        }

        return $this;
    }

    /**
     * @param string $column
     * @param array $ranges
     * @return $this
     */
    public function range(string $column, array $ranges): self
    {
        $this->aggregationEndpoint->addRangeBucket($column, $ranges);

        return $this;
    }


    /**
     * @param string $column
     * @return $this
     */
    public function stats(string $column): self
    {
        return $this->aggregation($column, 'stats');
    }

    /**
     * @param string $column
     * @return $this
     */
    public function sum(string $column): self
    {
        return $this->aggregation($column, 'sum');
    }

    /**
     * @param string $column
     * @return $this
     */
    public function min(string $column): self
    {
        return $this->aggregation($column, 'min');
    }

    /**
     * @param string $column
     * @return $this
     */
    public function max(string $column): self
    {
        return $this->aggregation($column, 'max');
    }

    /**
     * @param string $column
     * @return $this
     */
    public function avg(string $column): self
    {
        return $this->aggregation($column, 'avg');
    }

    /**
     * @param array $options
     * @return ElasticEntity
     */
    public function get(array $options = []): ElasticEntity
    {
        return $this->newEsEngine()->search($options);
    }

    /**
     * @param array $options
     * @return ElasticEntity
     */
    public function first(array $options = []): ElasticEntity
    {
        return $this->limit(1)->get($options);
    }

    /**
     * @param Collection $models
     * @return bool
     */
    public function update(Collection $models): bool
    {
        return $this->newEsEngine()->update($models);
    }

    /**
     * @param Collection $models
     * @return bool
     */
    public function delete(Collection $models): bool
    {
        return $this->newEsEngine()->delete($models);
    }

    /**
     * @return Engine
     */
    public function newEsEngine(): Engine
    {
        if (!$this->esEngine) {
            $this->esEngine = new Engine();
            $this->esEngine->setBuilder($this);
        }

        return $this->esEngine;
    }

    /**
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * @return array
     */
    public function toSearchParams(): array
    {
        $result = [];
        foreach ($this->params as $field => $param) {
            if ($value = $this->{$field}) {
                $result[$param] = $value;
            }
        }
        $endpoints = [
            $this->queryEndpoint,
            $this->sortEndpoint,
            $this->highlightEndpoint,
            $this->aggregationEndpoint
        ];
        foreach ($endpoints as $endpoint) {
            if ($output = $endpoint->normalize()) {
                $result['body'][$endpoint->getName()] = $output;
            }
        }

        return $result;
    }

    /**
     * @param string $relation
     * @return $this
     */
    protected function setRelation(string $relation): self
    {
        $this->queryEndpoint->setRelation($relation);

        return $this;
    }

    /**
     * Add an array of where clauses to the query.
     *
     * @param array $column
     * @param string $occur
     * @return $this
     */
    protected function addArrayOfWheres(array $column, string $occur): self
    {
        $this->whereBool(function (Builder $query) use ($column, $occur) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->where(...array_values($value));
                } else {
                    $query->where($key, '=', $value, $occur);
                }
            }
        });

        return $this;
    }


    /**
     * Prepare the value and operator for a where clause.
     *
     * @param mixed $value
     * @param mixed $operator
     * @param bool $useDefault
     * @return array
     * @throws ElasticException
     */
    protected function prepareValueAndOperator(mixed $value = null, mixed $operator = null, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        } elseif ($this->isInvalidOperatorAndValue($operator, $value)) {
            throw new ElasticException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * @param string $operator
     * @param mixed $value
     * @return bool
     */
    protected function isInvalidOperatorAndValue(string $operator, mixed $value): bool
    {
        return is_null($value) && in_array($operator, $this->operators);
    }

    /**
     * Determine if the given operator is supported.
     *
     * @param string $operator
     * @return bool
     */
    protected function isInvalidOperator(string $operator): bool
    {
        return !in_array(strtolower($operator), $this->operators, true);
    }
}