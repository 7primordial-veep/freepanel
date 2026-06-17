<?php

namespace App\Database;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as PdoMySQLDriver;
use App\Entity\DatabaseServer as DatabaseServerEntity;
use App\Entity\Database as DatabaseEntity;
use App\Entity\DatabaseUser as DatabaseUserEntity;
class Connection
{
    const ENGINE_MYSQL = "MySQL";
    const ENGINE_MARIA_DB = "MariaDB";
    private DatabaseServerEntity $databaseServerEntity;
    private ?DoctrineConnection $connection = null;
    public function __construct(DatabaseServerEntity $databaseServerEntity)
    {
        $this->databaseServerEntity = $databaseServerEntity;
    }
    public function connect($timeout = 10)
    {
        if (true === is_null($this->connection)) {
            try {
                $params = ["host" => $this->databaseServerEntity->getHost(), "port" => $this->databaseServerEntity->getPort(), "user" => $this->databaseServerEntity->getUserName(), "password" => $this->databaseServerEntity->getDecryptedPassword()];
                $driverOptions = [\PDO::ATTR_TIMEOUT => $timeout, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
                $certificate = $this->databaseServerEntity->getCertificate();
                if (false === empty($certificate)) {
                    $tmpCertificateFile = tempnam(sys_get_temp_dir(), "clp-tmp-certificate-");
                    file_put_contents($tmpCertificateFile, $certificate);
                    $driverOptions[\PDO::MYSQL_ATTR_SSL_CA] = $tmpCertificateFile;
                    $driverOptions[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                }
                $params["driverOptions"] = $driverOptions;
                $driver = new PdoMySQLDriver();
                $this->connection = new DoctrineConnection($params, $driver);
                $this->connection->connect();
            } finally {
                if (true === isset($tmpCertificateFile)) {
                    unlink($tmpCertificateFile);
                }
            }
        }
        return $this->connection;
    }
    public function getDatabases() : array
    {
        $this->connect();
        $databases = [];
        $databasesResult = $this->connection->executeQuery("show databases");
        $databasesRows = $databasesResult->fetchAllAssociative();
        foreach ($databasesRows as $databasesRow) {
                if (!(true == isset($databasesRow["Database"]) && false === empty($databasesRow["Database"]))) {
                    continue;
                }
            $databases[] = $databasesRow["Database"];
        }
        return $databases;
    }
    public function createDatabase(DatabaseEntity $databaseEntity)
    {
        $databaseName = $databaseEntity->getName();
        if (false === $this->hasDatabase($databaseName)) {
            $databaseName = sprintf("`%s`", $databaseName);
            $schemaManager = $this->connection->createSchemaManager();
            $schemaManager->createDatabase($databaseName);
        }
    }
    public function deleteDatabase(DatabaseEntity $databaseEntity)
    {
        $databaseName = $databaseEntity->getName();
        if (true === $this->hasDatabase($databaseName)) {
            $databaseName = sprintf("`%s`", $databaseName);
            $schemaManager = $this->connection->createSchemaManager();
            $schemaManager->dropDatabase($databaseName);
        }
    }
    public function createUser(DatabaseUserEntity $databaseUserEntity) : void
    {
        $this->deleteUser($databaseUserEntity);
        $databaseEngine = $this->getEngine();
        $databaseUserName = $databaseUserEntity->getUserName();
        $databaseUserPassword = $databaseUserEntity->getDecryptedPassword();
        $databaseUserPermissions = $databaseUserEntity->getPermissions();
        $databaseEntity = $databaseUserEntity->getDatabase();
        if (self::ENGINE_MYSQL == $databaseEngine) {
            $sqlStatements = [sprintf("CREATE USER '%s'@'%%' IDENTIFIED WITH mysql_native_password BY %s;", $databaseUserName, $this->connection->quote($databaseUserPassword)), sprintf("GRANT USAGE ON *.* TO '%s'@'%%';", $databaseUserName)];
        } else {
            $sqlStatements = [sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY %s;", $databaseUserName, $this->connection->quote($databaseUserPassword)), sprintf("GRANT USAGE ON *.* TO '%s'@'%%';", $databaseUserName)];
        }
        foreach ($sqlStatements as $sql) {
            $this->connection->executeStatement($sql);
        }
        $databaseName = $databaseEntity->getName();
        $sqlStatements = [];
        if (DatabaseUserEntity::PERMISSIONS_READ_WRITE == $databaseUserPermissions) {
            $sqlStatements[] = sprintf("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, \n                CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON `%s`.* TO  '%s'@'%%';", $databaseName, $databaseUserName);
            $sqlStatements[] = sprintf("ALTER USER `%s`@`%%` REQUIRE NONE WITH MAX_QUERIES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0 MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 0;", $databaseUserName);
        } else {
            $sqlStatements[] = sprintf("GRANT SELECT ON `%s`.* TO  '%s'@'%%';", $databaseName, $databaseUserName);
        }
        foreach ($sqlStatements as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement("FLUSH PRIVILEGES;");
    }
    public function deleteUser(DatabaseUserEntity $databaseUserEntity)
    {
        $this->connect();
        $databaseUserName = $databaseUserEntity->getUserName();
        $this->connection->executeStatement(sprintf("DROP USER IF EXISTS `%s`;", $databaseUserName));
        $this->connection->executeStatement("FLUSH PRIVILEGES;");
    }
    public function hasDatabase(string $databaseName)
    {
        $this->connect();
        $databases = $this->getDatabases();
        $hasDatabase = in_array($databaseName, $databases);
        return $hasDatabase;
    }
    public function getEngine() : string
    {
        $engine = self::ENGINE_MYSQL;
        $version = strtolower($this->getVariableValue("version"));
        if (true === \str_contains($version, "maria")) {
            $engine = self::ENGINE_MARIA_DB;
        }
        return $engine;
    }
    public function getVersion() : ?string
    {
        $version = $this->getVariableValue("innodb_version");
        if (false === empty($version)) {
            preg_match("/^(?P<major>\\d+)(?:\\.(?P<minor>\\d+)(?:\\.(?P<patch>\\d+))?)?/", $version, $versionParts);
            if (true === isset($versionParts["major"]) && false === empty($versionParts["major"]) && true === isset($versionParts["minor"]) && false === empty($versionParts["minor"])) {
                $version = sprintf("%s.%s", $versionParts["major"], $versionParts["minor"]);
            }
        }
        return $version;
    }
    public function getVariableValue(string $key) : ?string
    {
        $this->connect();
        $statement = $this->connection->prepare("SHOW VARIABLES LIKE :key");
        $result = $statement->execute(["key" => $key]);
        $result = $result->fetchAssociative();
        $value = $result["Value"] ?? '';
        return $value;
    }
}