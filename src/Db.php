<?php
/** Tiny PDO wrapper (MariaDB). Throws on error, returns arrays. */
class Db
{
    /** @var PDO */
    private $pdo;

    public function __construct(array $dbCfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbCfg['host'], $dbCfg['port'], $dbCfg['name'], $dbCfg['charset']
        );
        $this->pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** INSERT from an assoc array, returns lastInsertId. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
        $this->pdo->prepare($sql)->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    /** UPDATE by primary key id. */
    public function update(string $table, int $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        $data['__id'] = $id;
        $this->pdo->prepare("UPDATE `$table` SET $set WHERE id = :__id")->execute($data);
    }

    public function query(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
