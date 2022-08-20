<?php
/*
 * ॐ  Om Brahmarppanam  ॐ
 *
 * simple-db-migrator.php
 * Created at: Thu Jul 20 2022 19:34:40 GMT+0530 (GMT+05:30)
 *
 * Copyright 2022 Harish Karumuthil <harish2704@gmail.com>
 *
 * Use of this source code is governed by an MIT-style
 * license that can be found in the LICENSE file or at
 * https://opensource.org/licenses/MIT.
 *
 */

$dbConf = ["dsn" => "sqlite:./migrationtest.db", "user" => null, "password" => null];
// $dbConf = [ "dsn" => "mysql:host=172.20.1.3;dbname=migrationtest", "user" => "root", "password" => "xxxxxx", ];
// $dbConf = [ "dsn" => "pgsql:host=172.17.0.2;dbname=migrationtest", "user" => "postgres", "password" => "xxxxxx", ];
$MIGRAION_ROOT = __DIR__ . "/migrations";
$L;

class Logger
{
  private $levels = ["error", "warning", "info", "log", "debug"];
  public function __construct($level)
  {
    $this->level = $level;
  }

  private function _log($level, $args)
  {
    echo date("D M d, Y G:i") . " [$level] : " . implode(", ", $args) . "\n";
  }

  public function __call($name, $arguments)
  {
    $targetLevel = array_search($name, $this->levels);
    if ($targetLevel !== false && $targetLevel <= $this->level) {
      $this->_log($name, $arguments);
    }
  }
}

class MigrationItem
{
  /**
   * @param $v {int} Version number
   */
  public function __construct($v)
  {
    global $MIGRAION_ROOT;
    $this->v = $v;
    $this->upFile = sprintf("%s/up/%03d.sql", $MIGRAION_ROOT, $v);
    $this->downFile = sprintf("%s/down/%03d.sql", $MIGRAION_ROOT, $v);
  }

  public function getSQL($fname)
  {
    return file_get_contents($fname);
  }

  public function getUpSQL()
  {
    return $this->getSQL($this->upFile);
  }

  public function getDownSql()
  {
    return $this->getSQL($this->downFile);
  }
}

class Migrator
{
  public function __construct()
  {
    global $dbConf, $L;
    $this->db = new PDO($dbConf["dsn"], $dbConf["user"], $dbConf["password"]);
    $this->L = $L;
  }

  private function runSQLTransaction($sql)
  {
    $this->L->debug("Runing SQL");
    $this->L->debug($sql);
    $res = $this->db->exec("BEGIN;\n" . $sql . "\nCOMMIT;");
  }

  /*
   * Get array of pending version numbers
   * @return int[]
   */
  public function getPendingMigrations()
  {
    $lastRanMigration = $this->getLastRanMigration();
    $availableMigrations = $this->getAvailableMigrations();

    if ($lastRanMigration == 0) {
      return $availableMigrations;
    }

    $lastMigrationIdx = array_search($lastRanMigration, $availableMigrations);

    if ($lastMigrationIdx === null) {
      throw new Exception(
        "Inconsistent state: Last migration is missing in filesystem"
      );
    }
    return array_slice($availableMigrations, $lastMigrationIdx + 1);
  }

  /*
   * Get array of available verion numbers
   * @return int[]
   */
  private function getAvailableMigrations()
  {
    global $MIGRAION_ROOT;
    $files = scandir("$MIGRAION_ROOT/up");
    $out = [];
    foreach ($files as $fname) {
      $match = [];
      $matches = preg_match('/^([0-9]*).sql$/', $fname, $match);
      if ($matches > 0) {
        $out[] = (int) $match[1];
      }
    }
    sort($out);
    return $out;
  }

  /*
   * @return int
   */
  private function getLastRanMigration()
  {
    try {
      $result = $this->db
        ->query(
          "SELECT version from db_migrations order by version desc limit 1",
          PDO::FETCH_ASSOC
        )
        ->fetchAll();
    } catch (Exception $e) {
      $this->L->error("db_migrations table doesn't exists. Please run setup");
      throw $e;
    }
    if ($result) {
      return $result[0]["version"];
    }
    return 0;
  }

  public function runUp()
  {
    $this->L->warning("Running up");
    $pendingMigrations = $this->getPendingMigrations();
    $this->L->warning("Pending migrations " . json_encode($pendingMigrations));
    foreach ($pendingMigrations as $migrationV) {
      $this->L->warning("Running migration " . $migrationV);
      $migrationItem = new MigrationItem($migrationV);
      $sql = $migrationItem->getUpSQL();
      $this->runSQLTransaction($sql);
      $this->db
        ->prepare(
          "INSERT INTO db_migrations
             (version, created_at, up_sql, down_sql) VALUES (?, ?, ?, ?)"
        )
        ->execute([
          $migrationV,
          date("Y-m-d H:i:s"),
          $sql,
          $migrationItem->getDownSql(),
        ]);
    }
    $this->L->warning("executed all pending migrations");
  }

  public function setup()
  {
    try {
      $result = $this->db
        ->query(
          "SELECT version from db_migrations order by version desc limit 1",
          PDO::FETCH_ASSOC
        )
        ->fetchAll();
    } catch (Exception $e) {
      $this->L->warning("db_migrations table doesn't exists.");
      $this->L->info("Creating db_migrations table ...");
      return $this->db->query("
      CREATE TABLE db_migrations (
        version int NOT NULL,
        created_at VARCHAR(20) DEFAULT NULL,
        up_sql text DEFAULT NULL,
        down_sql text DEFAULT NULL,
        PRIMARY KEY (version)
      )");
    }
    $this->L->warning("db_migrations table already exists. Skipping setup");
  }

  /*
   * @return string
   */
  private function getDownSqlFromDb($v)
  {
    $res = $this->db
      ->query(
        "select down_sql from db_migrations where version = $v",
        PDO::FETCH_ASSOC
      )
      ->fetchAll();
    return $res[0]["down_sql"];
  }

  public function runDown()
  {
    $this->L->warning("Rolling back last migration ...");
    $lastRanMigration = $this->getLastRanMigration();
    if (!$lastRanMigration) {
      throw new Exception("There is no migration to rollback");
    }
    $this->L->warning("last migration is $lastRanMigration");
    $migrationItem = new MigrationItem($lastRanMigration);
    $downSqlFromDisk = $migrationItem->getDownSql();
    $downSqlFromDb = $this->getDownSqlFromDb($lastRanMigration);
    if ($downSqlFromDisk != $downSqlFromDb) {
      $this->L->error(
        "rollback sql stored in db does not match with the sql in filesystem"
      );
      $this->L->error("SQL from db");
      $this->L->error($downSqlFromDb);
      $this->L->error("SQL from filesystem");
      $this->L->error($downSqlFromDisk);
      $this->L->error("Please manually fix this error and run again");
      throw new Exception(
        "rollback sql stored in db does not match with the sql in filesystem"
      );
    }

    $this->runSQLTransaction($downSqlFromDisk);
    $this->db
      ->prepare("DELETE FROM db_migrations WHERE version = ?")
      ->execute([$lastRanMigration]);
    $this->L->warning("Rollback completed");
  }

  function currentStatus()
  {
    $lastRanMigration = $this->getLastRanMigration();
    $this->L->warning("last migration is $lastRanMigration");
    $pendingMigrations = $this->getPendingMigrations();
    $this->L->warning("Pending migrations " . json_encode($pendingMigrations));
  }
}

class Application
{
  public function __construct()
  {
    $this->opts = getopt("ldsvh", ["list", "down", "setup", "verbose", "help"]);
    $this->logLevel = 1;
    $verbose = $this->getOption("verbose");
    if ($verbose) {
      if ($verbose === true) {
        $verbose = [$verbose];
      }
      $this->logLevel += count($verbose);
    }
  }

  private function getOption($name)
  {
    foreach ([$name[0], $name] as $k) {
      if (isset($this->opts[$k])) {
        return $this->opts[$k] === false ? true : $this->opts[$k];
      }
    }
  }

  function help()
  {
    echo "Description:
  A simple database migration tool

Usage:
  php simple-db-migrator.php [options]

Options:
  -l, --list            Show the current status of applied migrations
  -s, --setup           Create db_migrations table in db and run all pending migrations
  -d, --down            Roll back last migration
  -h, --help            Display help for the given command. When no command is given display help for the db:migrate command
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1-3 => info,log,debug
";
  }

  function execute()
  {
    global $L;
    if ($this->getOption("help")) {
      return $this->help();
    }
    $L = new Logger($this->logLevel);
    $L->info("Starting migrator");
    $migrator = new Migrator();

    if ($this->getOption("list")) {
      $migrator->currentStatus();
      return;
    }

    if ($this->getOption("setup")) {
      $migrator->setup();
    }
    if ($this->getOption("down")) {
      $migrator->runDown();
    } else {
      $migrator->runUp();
    }
    return 0;
  }
}

(new Application())->execute();
