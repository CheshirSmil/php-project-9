<?php

namespace Hexlet\Code;

use Dotenv\Dotenv;

/**
 * Создание класса Connection
 */
final class Connection
{
    /**
     * Connection
     * тип @var
     */
    private static ?Connection $conn = null;

    /**
     * Подключение к базе данных и возврат экземпляра объекта \PDO
     * @return \PDO
     * @throws \Exception
     */
    public function connect()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();

         $databaseUrl = parse_url($_ENV['DATABASE_URL']);
        if (!$databaseUrl) {
            throw new \Exception("Error reading database configuration file");
        }

            $params['host'] = $databaseUrl['host'];
            $params['port'] = $databaseUrl['port'] ?? '';
            $params['path'] = ltrim($databaseUrl['path'] ?? '', '/');
            $params['user'] = $databaseUrl['user'] ?? '';
            $params['pass'] = $databaseUrl['pass'] ?? '';

            //var_dump($params);

        // подключение к базе данных postgresql
        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['path'],
            $params['user'],
            $params['pass']
        );
        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
    /**
     * возврат экземпляра объекта Connection
     * тип @return
     */

    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }

        return static::$conn;
    }

    protected function __construct()
    {
    }
}
