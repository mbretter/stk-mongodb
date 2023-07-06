<?php

// db.sessions.ensureIndex({"expires":1},{expireAfterSeconds: 0 });

namespace Stk\MongoDB;

use Closure;
use Exception;
use MongoDB\BSON\UTCDatetime as MongoDate;
use MongoDB\Collection;
use Psr\Log\LoggerInterface;
use SessionHandlerInterface;
use stdClass;
use Stk\Service\Injectable;

class SessionManager implements Injectable, SessionHandlerInterface
{
    protected ?LoggerInterface $logger = null;

    protected int $timeout;

    protected Collection $collection;

    protected bool $debug = false;

    protected ?Closure $onWrite;

    public const DEFAULT_TIMEOUT = 86400; // inactivity timeout

    public function __construct(Collection $collection, array $config = [])
    {
        $this->collection = $collection;

        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : self::DEFAULT_TIMEOUT;
        $this->debug   = isset($config['debug']) && (bool) $config['debug'];
        $this->onWrite = $config['onWrite'] ?? null;
        $this->init();
    }

    protected function init(): void
    {
        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            /** @phpstan-ignore-next-line */
            [$this, 'gc']
        );
    }

    public function __destruct()
    {
        session_write_close();
    }

    public function open(string $path, string $name): bool
    {
        global $sess_save_path;

        $sess_save_path = $path;

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $ret = "";

        $this->debug(__METHOD__ . "id:$id");

        try {
            $session = $this->collection->findOne([
                '_id'     => $id,
                'expires' => ['$gt' => new MongoDate(time() * 1000)]
            ], ['typeMap' => ['root' => 'array']]);

            if ($session !== null) {
                if (is_array($session)) {
                    $ret = $session["data"];
                } else {
                    /** @var stdClass $session */
                    $ret = $session->data;
                }
            }

        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());
        }

        return $ret;
    }

    public function write(string $id, string $data): bool
    {
        try {
            $set = [
                '_id'     => $id,
                'data'    => $data,
                'expires' => new MongoDate(1000 * (time() + $this->timeout))
            ];

            if ($this->onWrite !== null) {
                $onWrite = $this->onWrite;
                $extra   = $onWrite($id, $data);
                if (is_array($extra)) {
                    $set = array_merge($extra, $set);
                }
            }
            $this->collection->replaceOne(['_id' => $id], $set, ['upsert' => true]);
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        try {
            $this->collection->deleteOne(['_id' => $id]);
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $res = $this->collection->deleteMany(['expires' => ['$lt' => new MongoDate(time() * 1000)]]);

            $delCount = $res->getDeletedCount();
            // null means delete has not yet been acknowledged
            if ($delCount === null) {
                return false;
            }

            return $delCount;
        } catch (Exception $e) {
            $this->error(__METHOD__ . ':' . $e->getMessage());

            return false;
        }
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->logger && $this->debug) {
            $this->logger->debug($message, $context);
        }
    }

    public function error(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

}
