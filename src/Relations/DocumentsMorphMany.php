<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use MadeByClowd\Documentable\Models\Document;

/**
 * `documents.documentable_id` is a string column so one table can serve owners with int, uuid and ulid keys.
 * Laravel's stock `MorphMany` assumes the owner key and the foreign key share a type. For an owner with an
 * integer primary key, PostgreSQL then rejects the query (`character varying = integer`): it receives the key
 * as an integer, eager loads inline the keys as unquoted integers, and `whereHas`/`withCount` compare the two
 * columns directly. SQLite and MySQL coerce silently, which is why the problem only shows on PostgreSQL.
 *
 * This relation sends the owner key as a string everywhere it meets `documentable_id`.
 *
 * A cast in `whereHas` cannot use an index on `documentable_id`. The typed key column planned for v3 removes
 * the need for this class, at which point it is deleted.
 *
 * @internal Returned by `Documentable::documents()`; do not instantiate or extend it.
 *
 * @extends MorphMany<Document, Model>
 */
final class DocumentsMorphMany extends MorphMany
{
    /**
     * The owner key as a string: used for the lazy-load constraint and for the foreign key of documents made
     * through the relation, so both match the column's type.
     */
    public function getParentKey()
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    /** {@inheritDoc} */
    public function addEagerConstraints(array $models)
    {
        $this->getRelationQuery()
            ->whereIn($this->foreignKey, array_map('strval', $this->getKeys($models, $this->localKey)))
            ->where($this->morphType, $this->morphClass);
    }

    /** {@inheritDoc} */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($query->getQuery()->from === $parentQuery->getQuery()->from) {
            return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        }

        $grammar = $query->getQuery()->getGrammar();

        return $query->select($columns)
            ->whereRaw($this->castToString($grammar->wrap($this->getQualifiedParentKeyName()), $query->getConnection()->getDriverName()).' = '.$grammar->wrap($this->getExistenceCompareKey()))
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }

    /**
     * Wraps a column expression so it compares with a string column on the connection that runs the query. A string-keyed
     * owner needs nothing. SQLite compares by affinity and MySQL coerces the integer itself; casting there would
     * only add a collation clash, because `CAST(... AS char)` takes the connection collation, not the column's
     * (`Illegal mix of collations`). Only PostgreSQL and SQL Server refuse the mixed comparison.
     */
    private function castToString(string $wrappedColumn, string $driver): string
    {
        if ($this->parent->getKeyName() === $this->localKey && $this->parent->getKeyType() === 'string') {
            return $wrappedColumn;
        }

        return match ($driver) {
            'pgsql' => "CAST({$wrappedColumn} AS varchar)",
            'sqlsrv' => "CAST({$wrappedColumn} AS nvarchar(255))",
            default => $wrappedColumn,
        };
    }
}
