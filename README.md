# simple-db-migrator
A simple database migration tool written in PHP

# Quick introduction.

1. This is a simple database migration commandline application written in php.
2. Contents of each SQL file is run as single transaction.
3. The migrator tool will save the `down` migration (rollback SQL statement) in DB and cross verify it with the current version of the rollback SQL statement present in the disk and complain if both are different. 

# Usage

Migrations are arranged in the following directory structure.
```
├── migrations
│   ├── down
│   │   ├── 001.sql
│   │   ├── 002.sql
│   │   ├── 003.sql
│   │   └── 004.sql
│   └── up
│       ├── 001.sql
│       ├── 002.sql
│       ├── 003.sql
│       └── 004.sql
└── simple-db-migrator.php

```

To create a new migration, Just create respective `xxx.sql` file in `up` and `down` directories.

## Run initial migration ( ie, create db_migration table )
```bash
# '-s' option stands for 'setup'
php simple-db-migrator.php -s
```

## Show status ( last completed migration and pending migrations )
```bash
php simple-db-migrator.php -l
```

## Apply all pending migrations ( up )
```bash
php simple-db-migrator.php
```

## Rollback last migration ( down )
```bash
php simple-db-migrator.php -d
```

## Show help
```
$ php simple-db-migrator.php -h
Description:
  A simple database migration tool

Usage:
  php simple-db-migrator.php [options]

Options:
  -l, --list            Show the current status of applied migrations
  -s, --setup           Create db_migrations table in db and run all pending migrations
  -d, --down            Roll back last migration
  -h, --help            Display help for the given command. When no command is given display help for the db:migrate command
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1-3 => info,log,debug
```

# Supported RDBMS
Tested with Mysql , PostgreSQL and Sqlite

# Cavets

1. From [php docs](https://www.php.net/manual/en/pdo.begintransaction.php),  "Some databases, including MySQL, automatically issue an implicit COMMIT when a database definition language (DDL) statement such as DROP TABLE or CREATE TABLE is issued within a transaction. The implicit COMMIT will prevent you from rolling back any other changes within the transaction boundary."
