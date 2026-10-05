<?php

namespace App\Tenancy\Database;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * MySQL/MariaDB users as `'name'@'%'`. Replaces stancl's PermissionControlledMySQLDatabaseManager,
 * which interpolates the password straight into CREATE USER (a typed `'` would break or inject).
 */
class MySqlDatabaseUserManager implements DatabaseUserManager
{
    /**
     * Rights on the tenant's own database. Everything migrations and the app need; no GRANT OPTION.
     *
     * @var list<string>
     */
    public const GRANTS = [
        'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE', 'CREATE TEMPORARY TABLES', 'CREATE VIEW',
        'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'INDEX', 'INSERT', 'LOCK TABLES', 'REFERENCES', 'SELECT',
        'SHOW VIEW', 'TRIGGER', 'UPDATE',
    ];

    public function __construct(protected Connection $connection) {}

    public function userExists(string $username): bool
    {
        return $this->connection->table('mysql.user')->where('user', $username)->exists();
    }

    public function createUser(string $username, string $password, string $database): void
    {
        $account = $this->account($username);

        $this->connection->statement("CREATE USER {$account} IDENTIFIED BY {$this->quote($password)}");
        $this->connection->statement(
            'GRANT '.implode(', ', self::GRANTS).' ON '.$this->quoteIdentifier($database).".* TO {$account}"
        );
    }

    public function dropUser(string $username): void
    {
        $this->connection->statement("DROP USER IF EXISTS {$this->account($username)}");
    }

    /**
     * `'name'@'%'`. An empty name would be MySQL's anonymous user, which matches every login.
     */
    protected function account(string $username): string
    {
        if ($username === '') {
            throw new InvalidArgumentException('Refusing an empty database username (MySQL anonymous user).');
        }

        return $this->quote($username)."@'%'";
    }

    protected function quote(string $value): string
    {
        return $this->connection->getPdo()->quote($value);
    }

    protected function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
