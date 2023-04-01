<?php

namespace Hexlet\Code\Misc;

function tableExists(\PDO $pdo, string $table)
{

    try {
        $result = $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (\PDOException $e) {
        return false;
    }

    return $result !== false;
}
