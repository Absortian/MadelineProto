<?php

namespace danog\MadelineProto\Db;

use Amp\Mysql\Pool;
use Amp\Producer;
use Amp\Promise;
use Amp\Sql\ResultSet;
use Amp\Success;
use danog\MadelineProto\Db\Driver\Mysql;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings\Database\Mysql as DatabaseMysql;

use function Amp\call;

class MysqlArray extends SqlArray
{
    protected string $table;
    protected DatabaseMysql $dbSettings;
    private Pool $db;

    // Legacy
    protected array $settings;

    public function __sleep(): array
    {
        return ['table', 'dbSettings'];
    }

    /**
     * Check if key isset.
     *
     * @param $key
     *
     * @return Promise<bool> true if the offset exists, otherwise false
     */
    public function isset($key): Promise
    {
        return call(fn () => yield $this->offsetGet($key) !== null);
    }


    public function offsetGet($offset): Promise
    {
        return call(function () use ($offset) {
            if ($cached = $this->getCache($offset)) {
                return $cached;
            }

            $row = yield $this->request(
                "SELECT `value` FROM `{$this->table}` WHERE `key` = :index LIMIT 1",
                ['index' => $offset]
            );

            if ($value = $this->getValue($row)) {
                $this->setCache($offset, $value);
            }

            return $value;
        });
    }

    /**
     * Set value for an offset.
     *
     * @link https://php.net/manual/en/arrayiterator.offsetset.php
     *
     * @param string $index <p>
     * The index to set for.
     * </p>
     * @param $value
     *
     * @throws \Throwable
     */

    public function offsetSet($index, $value): Promise
    {
        if ($this->getCache($index) === $value) {
            return new Success();
        }

        $this->setCache($index, $value);

        $request = $this->request(
            "
            INSERT INTO `{$this->table}` 
            SET `key` = :index, `value` = :value 
            ON DUPLICATE KEY UPDATE `value` = :value
        ",
            [
                'index' => $index,
                'value' => \serialize($value),
            ]
        );

        //Ensure that cache is synced with latest insert in case of concurrent requests.
        $request->onResolve(fn () => $this->setCache($index, $value));

        return $request;
    }

    /**
     * Unset value for an offset.
     *
     * @link https://php.net/manual/en/arrayiterator.offsetunset.php
     *
     * @param string $index <p>
     * The offset to unset.
     * </p>
     *
     * @return Promise
     * @throws \Throwable
     */
    public function offsetUnset($index): Promise
    {
        $this->unsetCache($index);

        return $this->request(
            "
                    DELETE FROM `{$this->table}`
                    WHERE `key` = :index
                ",
            ['index' => $index]
        );
    }

    /**
     * Get array copy.
     *
     * @return Promise<array>
     * @throws \Throwable
     */
    public function getArrayCopy(): Promise
    {
        return call(function () {
            $iterator = $this->getIterator();
            $result = [];
            while (yield $iterator->advance()) {
                [$key, $value] = $iterator->getCurrent();
                $result[$key] = $value;
            }
            return $result;
        });
    }

    public function getIterator(): Producer
    {
        return new Producer(function (callable $emit) {
            $request = yield $this->db->execute("SELECT `key`, `value` FROM `{$this->table}`");

            while (yield $request->advance()) {
                $row = $request->getCurrent();
                yield $emit([$row['key'], $this->getValue($row)]);
            }
        });
    }

    /**
     * Count elements.
     *
     * @link https://php.net/manual/en/arrayiterator.count.php
     * @return Promise<int> The number of elements or public properties in the associated
     * array or object, respectively.
     * @throws \Throwable
     */
    public function count(): Promise
    {
        return call(function () {
            $row = yield $this->request("SELECT count(`key`) as `count` FROM `{$this->table}`");
            return $row[0]['count'] ?? 0;
        });
    }

    private function getValue(array $row)
    {
        if ($row) {
            if (!empty($row[0]['value'])) {
                $row = \reset($row);
            }
            return \unserialize($row['value']);
        }
        return null;
    }

    /**
     * Initialize connection.
     *
     * @param DatabaseMysql $settings
     * @return \Generator
     */
    public function initConnection($settings): \Generator
    {
        if (!isset($this->db)) {
            $this->db = yield from Mysql::getConnection($settings);
        }
    }

    /**
     * Create table for property.
     *
     * @return array|null
     * @throws \Throwable
     */
    protected function prepareTable(): \Generator
    {
        Logger::log("Creating/checking table {$this->table}", Logger::WARNING);
        return yield $this->request("
            CREATE TABLE IF NOT EXISTS `{$this->table}`
            (
                `key` VARCHAR(255) NOT NULL,
                `value` MEDIUMBLOB NULL,
                `ts` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`key`)
            )
            ENGINE = InnoDB
            CHARACTER SET 'utf8mb4' 
            COLLATE 'utf8mb4_general_ci'
        ");
    }

    protected function renameTable(string $from, string $to): \Generator
    {
        Logger::log("Renaming table {$from} to {$to}", Logger::WARNING);
        yield $this->request("
            ALTER TABLE `{$from}` RENAME TO `{$to}`;
        ");

        yield $this->request("
            DROP TABLE IF EXISTS `{$from}`;
        ");
    }

    /**
     * Perform async request to db.
     *
     * @param string $query
     * @param array $params
     *
     * @return Promise
     * @throws \Throwable
     */
    private function request(string $query, array $params = []): Promise
    {
        return call(function () use ($query, $params) {
            //Logger::log([$query, $params], Logger::VERBOSE);

            if (empty($this->db) || !$this->db->isAlive()) {
                Logger::log('No database connection', Logger::WARNING);
                return [];
            }

            if (
                !empty($params['index'])
                && !\mb_check_encoding($params['index'], 'UTF-8')
            ) {
                $params['index'] = \mb_convert_encoding($params['index'], 'UTF-8');
            }

            try {
                $request = yield $this->db->execute($query, $params);
            } catch (\Throwable $e) {
                Logger::log($e->getMessage(), Logger::ERROR);
                return [];
            }

            $result = [];
            if ($request instanceof ResultSet) {
                while (yield $request->advance()) {
                    $result[] = $request->getCurrent();
                }
            }
            return $result;
        });
    }
}
