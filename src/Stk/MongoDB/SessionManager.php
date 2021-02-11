<?php

// db.sessions.ensureIndex({"expires":1},{expireAfterSeconds: 0 });

namespace Stk\MongoDB;

use Exception;
use MongoDB\BSON\UTCDatetime as MongoDate;
use MongoDB\Collection;
use Psr\Log\LoggerAwareTrait;
use SessionHandlerInterface;
use Stk\Service\Injectable;


class SessionManager implements Injectable, SessionHandlerInterface
{
    use LoggerAwareTrait;

    protected int $timeout;

    protected Collection $collection;

    protected bool $debug = false;

    public const DEFAULT_TIMEOUT = 86400; // inactivity timeout

    public function __construct(Collection $collection, array $config = [])
    {
        $this->collection = $collection;

        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : self::DEFAULT_TIMEOUT;
        $this->debug   = isset($config['debug']) ? (bool) $config['debug'] : false;
        $this->init();
    }

    protected function init()
    {
        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            [$this, 'gc']
        );
    }

    public function __destruct()
    {
        session_write_close();
    }

    public function open($save_path, $name): bool
    {
        global $sess_save_path;

        $sess_save_path = $save_path;

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $ret = "";

        $this->debug(__METHOD__ . "id:$id");

        try {
            $session = $this->collection->findOne([
                '_id'     => $id,
                'expires' => ['$gt' => new MongoDate(time() * 1000)]
            ], ['typeMap' => ['root' => 'array']]);

            if ($session !== null) {
                $ret = $session["data"];
            }

        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());
        }

        return $ret;
    }

    public function write($id, $data): bool
    {
        try {
            $this->collection->replaceOne(
                [
                    '_id' => $id
                ],
                [
                    '_id'     => $id,
                    'data'    => $data,
                    'expires' => new MongoDate(1000 * (time() + $this->timeout))
                ],
                [
                    'upsert' => true
                ]);
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }

        return true;
    }

    public function destroy($id): bool
    {
        try {
            $this->collection->deleteOne(['_id' => $id]);
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param int $maxlifetime
     * @return bool|int
     */
    public function gc($maxlifetime)
    {
        try {
            $res = $this->collection->deleteMany(['expires' => ['$lt' => new MongoDate(time() * 1000)]]);

            return $res->getDeletedCount();
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }

    public function debug(string $message, array $context = [])
    {
        if ($this->logger && $this->debug) {
            $this->logger->debug($message, $context);
        }
    }

    public function error(string $message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

}
