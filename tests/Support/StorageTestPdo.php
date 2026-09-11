<?php

declare(strict_types=1);

final class StorageSqlMetrics
{
    public int $queries=0;
    public int $rows=0;
    public int $maxParameters=0;
    public function reset():void{$this->queries=0;$this->rows=0;$this->maxParameters=0;}
    public function snapshot():array{return ['queries'=>$this->queries,'rows'=>$this->rows,'maxParameters'=>$this->maxParameters];}
}

final class StorageCountingStatement extends PDOStatement
{
    protected function __construct(private StorageSqlMetrics $metrics) {}
    public function execute(?array $params=null):bool {
        $this->metrics->queries++;$this->metrics->maxParameters=max($this->metrics->maxParameters,count($params??[]));
        return parent::execute($params);
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        $rows=parent::fetchAll($mode,...$args);$this->metrics->rows+=count($rows);return $rows;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);$this->metrics->rows+=(int)($row!==false);return $row;
    }
}

/** Isolates every SQL table while preserving real, independent MariaDB connections. */
final class StorageTestPdo extends PDO
{
    public function __construct(private PDO $connection, private array $tables = []) {
        $this->metrics = new StorageSqlMetrics();
        $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS,[StorageCountingStatement::class,[$this->metrics]]);
    }
    public readonly StorageSqlMetrics $metrics;
    private function sql(string $sql): string
    {
        if ($this->tables === []) { return $sql; }
        return preg_replace_callback('/\\b(' . implode('|', array_keys($this->tables)) . ')\\b/', fn(array $m): string => $this->tables[$m[1]], $sql);
    }
    public function exec(string $statement): int|false { $this->metrics->queries++; return $this->connection->exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->connection->prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    { $this->metrics->queries++; return $fetchMode === null ? $this->connection->query($this->sql($query)) : $this->connection->query($this->sql($query), $fetchMode, ...$fetchModeArgs); }
    public function beginTransaction(): bool { return $this->connection->beginTransaction(); }
    public function commit(): bool { return $this->connection->commit(); }
    public function rollBack(): bool { return $this->connection->rollBack(); }
    public function inTransaction(): bool { return $this->connection->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->connection->lastInsertId($name); }
    public function getAttribute(int $attribute): mixed { return $this->connection->getAttribute($attribute); }
    public function setAttribute(int $attribute, mixed $value): bool { return $this->connection->setAttribute($attribute, $value); }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return $this->connection->quote($string, $type); }
}

