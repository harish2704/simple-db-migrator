<?php
/*
 * ॐ  Om Brahmarppanam  ॐ
 *
 * schema/migrator.php
 * Created at: Thu Jul 20 2022 19:34:40 GMT+0530 (GMT+05:30)
 *
 * Copyright 2022 Harish Karumuthil <harish2704@gmail.com>
 *
 * Use of this source code is governed by an MIT-style
 * license that can be found in the LICENSE file or at
 * https://opensource.org/licenses/MIT.
 *
 */

require_once __DIR__ . "/../vendor/autoload.php";

// Load database credentials from database.php 
// where  $dbPass, $dbName, $dbHost, $dbUser are defined.
include __DIR__ . "/../database.php";

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Application;

$MIGRAION_ROOT = __DIR__ . "/migrations";
$L;

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
    global $dbPass, $dbName, $dbHost, $dbUser, $L;
    $this->db = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $this->L = $L;
  }

  private function runSQLTransaction($sql)
  {
    $sql = "BEGIN;
    $sql
      COMMIT;";

    $this->L->debug("Runing SQL");
    $this->L->debug($sql);
    return $this->db->query($sql);
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
      $matches = preg_match('/^([0-9])*.sql$/', $fname, $match);
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
      if ($e->errorInfo[0] == "42S02") {
        throw new Exception(
          "db_migrations table doesn't exists. Please run setup"
        );
      }
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
          (version, created_at, up_sql, down_sql) VALUES (?, now(), ?, ?)"
        )
        ->execute([$migrationV, $sql, $migrationItem->getDownSql()]);
    }
    $this->L->warning("executed all pending migrations");
  }

  public function setup()
  {
    $this->L->info("Creating db_migrations table ...");
    return $this->db->query("
      CREATE TABLE db_migrations (
        version int unsigned NOT NULL,
        created_at datetime DEFAULT NULL,
        up_sql longtext DEFAULT NULL,
        down_sql longtext DEFAULT NULL,
        PRIMARY KEY (version)
      )");
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
}

class DbMigrate extends Command
{
  protected function configure()
  {
    $this->setName("db:migrate");
    $this->setDescription("Migrate DB to the latest version.");
    $this->addOption(
      "setup",
      "s",
      InputOption::VALUE_NONE,
      "Create db_migrations table in db"
    );
    $this->addOption(
      "down",
      "d",
      InputOption::VALUE_NONE,
      "Roll back last migration"
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    global $L;
    $L = new ConsoleLogger($output);
    $L->info("Starting migrator");
    $runSetup = $input->getOption("setup");
    $migrator = new Migrator();

    if ($runSetup) {
      $migrator->setup();
    }
    if ($input->getOption("down")) {
      $migrator->runDown();
    } else {
      $migrator->runUp();
    }
    return 0;
  }
}

$application = new Application("Migrator", "1.0.0");
$command = new DbMigrate();
$application->add($command);
$application->setDefaultCommand($command->getName(), true);
$application->run();
