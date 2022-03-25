<?php declare(strict_types=1);

namespace Fuzzyness\Macros;

use Fuzzyness\Matchers\ExactMatcher;
use Illuminate\Support\Facades\DB;
use Fuzzyness\Matchers\AcronymMatcher;
use Illuminate\Database\Query\Builder;
use Fuzzyness\Matchers\StartOfStringMatcher;
use Illuminate\Database\Query\Expression;
use Fuzzyness\Matchers\ConsecutiveCharactersMatcher;

class WhereFuzzy
{
    /**
     * The weights for the pattern matching classes.
     *
     **/
    protected static array $matchers = [
        ExactMatcher::class                 => 100,
        StartOfStringMatcher::class         => 50,
        AcronymMatcher::class               => 42,
        ConsecutiveCharactersMatcher::class => 40,
    ];

    protected static array $extendedMatchers = [
        StartOfWordsMatcher::class          => 35,
        StudlyCaseMatcher::class            => 32,
        InStringMatcher::class              => 30,
        TimesInStringMatcher::class         => 8,
    ];

    /**
     * Construct a fuzzy search expression.
     *
     **/
    public static function make($builder, $field, $value, bool $extended = false): Builder
    {
        $value       = static::escapeValue($value);
        $nativeField = '`' . str_replace('.', '`.`', trim($field, '` ')) . '`';

        if (! is_array($builder->columns) || empty($builder->columns)) {
            $builder->columns = ['*'];
        }

        $builder
            ->addSelect([static::pipeline($field, $nativeField, $value, $extended)])
            ->having('fuzzy_relevance_' . str_replace('.', '_', $field), '>', 0);

        return $builder;
    }

    /**
     * Construct a fuzzy OR search expression.
     *
     **/
    public static function makeOr($builder, $field, $value, bool $extended = false): Builder
    {
        $value       = static::escapeValue($value);
        $nativeField = '`' . str_replace('.', '`.`', trim($field, '` ')) . '`';

        if (! is_array($builder->columns) || empty($builder->columns)) {
            $builder->columns = ['*'];
        }

        $builder
            ->addSelect([static::pipeline($field, $nativeField, $value, $extended)])
            ->orHaving('fuzzy_relevance_' . str_replace('.', '_', $field), '>', 0);

        return $builder;
    }

    /**
     * Escape value input for fuzzy search.
     */
    protected static function escapeValue($value)
    {
        $value = str_replace(['"', "'", '`'], '', $value);
        $value = substr(DB::connection()->getPdo()->quote($value), 1, -1);

        return $value;
    }

    /**
     * Execute each of the pattern matching classes to generate the required SQL.
     *
     **/
    protected static function pipeline($field, $native, $value, bool $extended = false): Expression
    {
        $matchers = static::$matchers;

        if($extended) {
            $matchers = array_merge(static::$matchers, static::$extendedMatchers);
        }

        $sql = collect($matchers)->map(
            fn($multiplier, $matcher) => (new $matcher($multiplier))->buildQueryString("COALESCE($native, '')", $value)
        );

        return DB::raw($sql->implode(' + ') . ' AS fuzzy_relevance_' . str_replace('.', '_', $field));
    }
}
