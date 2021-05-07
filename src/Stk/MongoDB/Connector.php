<?php

namespace Stk\MongoDB;

use DateTime;
use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDatetime;
use MongoDB\DeleteResult;
use MongoDB\Driver\CursorInterface;
use MongoDB\GridFS\Bucket;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\Database;
use MongoDB\Collection;
use MongoDB\UpdateResult;
use Psr\Log\LoggerInterface;
use stdClass;
use Stk\Immutable\ImmutableInterface;
use Stk\Service\Injectable;


class Connector implements Injectable
{
    protected ?LoggerInterface $logger = null;

    protected Database $_database;

    protected Collection $_collection;

    /**
     * @param Database $mongodb
     * @param string $collectionName
     */
    public function __construct(Database $mongodb, string $collectionName)
    {
        $this->_database   = $mongodb;
        $this->_collection = $mongodb->selectCollection($collectionName);
    }

    public function setDatabase(Database $mongodb): void
    {
        $coll              = $this->_collection->getCollectionName();
        $this->_database   = $mongodb;
        $this->_collection = $mongodb->selectCollection($coll);
    }

    public function getDatabase(): Database
    {
        return $this->_database;
    }

    public function setCollection(string $collection): Connector
    {
        $this->_collection = $this->_database->selectCollection($collection);

        return $this;
    }

    public function getCollection(): Collection
    {
        return $this->_collection;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * generate either a new ObjectId or make an ObjectId from string
     * use the static version instead
     * @param ?string $id
     * @return ObjectId
     * @deprecated
     */
    public function newId(string $id = null): ObjectId
    {
        if ($id === null) {
            return new ObjectId();
        }

        return new ObjectId($id);
    }

    /**
     * @param string|null $id
     * @return ObjectId
     */
    public static function oId(string $id = null): ObjectId
    {
        return oId($id);
    }

    /**
     * makes an update if the _id field is set, otherwise an insert is made
     * returns the immutable, after an insert the _id is set into the immutable
     * and a new clone is returned.
     *
     * @param ImmutableInterface $row
     * @param array $fields
     * @param array $options
     *
     * @return ImmutableInterface
     */
    public function save(ImmutableInterface $row, array $fields = [], array $options = []): ImmutableInterface
    {
        if ($row->get('_id')) {
            $this->update($row, $fields, $options);
        } else {
            $insertResult = $this->insert($row, $options);
            if ($insertResult->getInsertedId() instanceof ObjectId) {
                return $row->set('_id', (string) $insertResult->getInsertedId());
            }
        }

        return $row;
    }

    /**
     * insertOne Immutable
     *
     * @param ImmutableInterface $row
     * @param array $options
     *
     * @return InsertOneResult
     */
    public function insert(ImmutableInterface $row, array $options = []): InsertOneResult
    {
        $this->debug(__METHOD__ . ":" . print_r($row, true));

        return $this->_collection->insertOne($row, $options);
    }

    /**
     * pass through to collections insertMany
     *
     * @param ImmutableInterface[] $rows
     * @param array $options
     *
     * @return InsertManyResult
     */
    public function insertMany(array $rows, array $options = []): InsertManyResult
    {
        $this->debug(__METHOD__ . ":" . print_r($rows, true));

        return $this->_collection->insertMany($rows, $options);
    }

    public function update(
        ImmutableInterface $row,
        array $fields = [],
        array $options = [],
        array $criteria = null
    ): ?UpdateResult {
        $values = $this->buildValueSet($row);

        if (isset($options['upsert'])) {
            // manually set __pclass, otherwise __pclass will not be set with upserts
            $values['__pclass'] = new Binary(get_class($row), Binary::TYPE_USER_DEFINED);
        }

        $setfields = [];
        if (count($values)) {
            $setfields = ['$set' => $values];
        }

        $fields = array_replace_recursive($fields, $setfields);

        if (!count($fields)) {
            $this->debug(__METHOD__ . ':nothing to update');

            return null;
        }

        if ($criteria === null) {
            $criteria = ['_id' => new ObjectId($row->get('_id'))];
        }

        $this->debug(__METHOD__ . ':' . $this->_collection->getCollectionName() . ':' . print_r($criteria,
                true) . ':' . print_r($fields, true));

        return $this->_collection->updateOne($criteria, $fields, $options);
    }

    /**
     * build an array of values, useable as parameter for $set
     * exclude id field and convert Datetime to UTCDateTime
     * only leaf nodes of the immutable are used, the corresponding path is converted into a dotted string
     * useful for updateing only parts of a document, without overwriting the whole document
     *
     * @param ImmutableInterface $row
     * @param string $prefix
     *
     * @return array
     */
    public function buildValueSet(ImmutableInterface $row, string $prefix = ''): array
    {
        $values = [];
        $row->walk(function ($path, $value) use (&$values, $prefix) {
            $key = is_array($path) ? implode('.', $path) : $path;
            if ($key == '_id') {
                return;
            }
            if (strlen($prefix)) {
                $key = sprintf('%s.%s', $prefix, $key);
            }

            if ($value instanceof DateTime) {
                $value = new UTCDatetime($value);
            }

            $values[$key] = $value;
        });

        return $values;
    }

    /**
     * pass through of updateMany method
     *
     * @param array $query
     * @param array $fields
     * @param array $options
     *
     * @return UpdateResult
     */
    public function updateMany(array $query = [], array $fields = [], array $options = []): UpdateResult
    {
        return $this->_collection->updateMany($query, $fields, $options);
    }

    /**
     * pass through of updateOne method
     *
     * @param array $query
     * @param array $fields
     * @param array $options
     *
     * @return UpdateResult
     */
    public function updateOne(array $query = [], array $fields = [], array $options = []): UpdateResult
    {
        return $this->_collection->updateOne($query, $fields, $options);
    }

    /**
     * delete a row, the row must provide an _id attribute as string
     *
     * @param ImmutableInterface $row
     *
     * @return DeleteResult
     */
    public function delete(ImmutableInterface $row): DeleteResult
    {
        return $this->deleteById($row->get('_id'));
    }

    /**
     * delete a document by id
     *
     * @param string $id
     *
     * @return DeleteResult
     */
    public function deleteById(string $id): DeleteResult
    {
        $this->debug(__METHOD__ . ":$id");

        return $this->_collection->deleteOne(['_id' => new ObjectId($id)]);
    }

    /**
     * pass through to collections deleteMany
     *
     * @param array $query
     * @param array $options
     *
     * @return DeleteResult
     */
    public function deleteMany(array $query = [], array $options = []): DeleteResult
    {
        return $this->_collection->deleteMany($query, $options);
    }

    /**
     * a pass through to update, by implicitely setting the upsert options
     *
     * @param array $criteria
     * @param ImmutableInterface $row
     * @param array $fields
     * @param array $options
     *
     * @return UpdateResult
     */
    public function upsert(array $criteria, ImmutableInterface $row, $fields = [], $options = []): ?UpdateResult
    {
        $options['upsert'] = true;

        return $this->update($row, $fields, $options, $criteria);
    }

    /**
     * IterartorIterator can be traversed using fetch(), usable when migrating from the legacy mongodb driver
     * may also be used, if traversing the result set more then once
     * implicitely transform _id string to ObjectId
     *
     * @param array $query
     * @param array $options
     *
     * @return IteratorIterator
     */
    public function find($query = [], $options = []): IteratorIterator
    {
        if (array_key_exists('_id', $query)) {
            $query['_id'] = is_string($query['_id']) ? new ObjectId($query['_id']) : $query['_id'];
        }

        $this->debug(__METHOD__ . ":" . $this->_collection . ":" . print_r($query,
                true) . ' options:' . print_r($options, true));

        $cursor = $this->_collection->find($query, $options);
        $it     = new IteratorIterator($cursor);
        $it->rewind();

        return $it;
    }

    /**
     * @param IteratorIterator $cursor
     *
     * @return ?ImmutableInterface
     */
    public function fetch(IteratorIterator $cursor): ?ImmutableInterface
    {
        $o = $cursor->current();
        if ($o === null) {
            return null;
        }

        $cursor->next();

        return $o;
    }

    /**
     * @param array|string|mixed $query
     * @param array $fields
     *
     * @return array|object|null|ImmutableInterface
     */
    public function findOne($query = [], $fields = [])
    {
        if (is_string($query)) {
            $query = ['_id' => new ObjectId($query)];
        } elseif (is_scalar($query)) {
            $query = ['_id' => $query];
        }

        $this->debug(__METHOD__ . ":" . $this->_collection . ":" . print_r($query, true));

        return $this->_collection->findOne($query, $fields);
    }

    /**
     * returns a traversable cursor result can be directly used with foreach
     * cursor can only traversed once
     *
     * @param array $query
     * @param array $options
     *
     * @return CursorInterface
     */
    public function query($query = [], $options = []): CursorInterface
    {
        if (array_key_exists('_id', $query)) {
            if (is_string($query['_id'])) {
                $query['_id'] = new ObjectId($query['_id']);
            }
        }

        return $this->_collection->find($query, $options);
    }

    /**
     * @param array $query
     * @param int $limit
     * @param int $skip
     *
     * @return int
     */
    public function count($query = [], $limit = 0, $skip = 0): int
    {
        $options = [];
        if ($limit > 0) {
            $options['limit'] = $limit;
        }
        if ($skip > 0) {
            $options['skip'] = $skip;
        }

        return $this->_collection->countDocuments($query, $options);
    }

    /**
     * returns a sequence number
     *
     * @param ?string $name the name of the sequence, defaults to the current selected collection name
     * @param string $seqCollection the collection holding the sequence values
     *
     * @return int
     */
    public function getNextSeq(string $name = null, string $seqCollection = 'sequence'): int
    {
        if ($name === null) {
            $name = $this->_collection->getCollectionName();
        }

        $coll = $this->_database->selectCollection($seqCollection);
        $ret  = $coll->findOneAndUpdate(['_id' => $name], ['$inc' => ['seq' => 1]], [
            'upsert'         => true,
            'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
        ]);

        if ($ret === null) {
            return 0;
        }

        if (is_array($ret)) {
            return (int) $ret['seq'];
        } else {
            /** @var stdClass $ret */
            return (int) $ret->seq;
        }
    }

    /**
     * @return Bucket
     */
    public function gridFs(): Bucket
    {
        return $this->_database->selectGridFSBucket();
    }

    /**
     * Debug logging if logger is available
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->debug($message, $context);
        }
    }
}

/**
 * @param string|null $id
 * @return ObjectId
 */
function oId(string $id = null): ObjectId
{
    if ($id === null) {
        return new ObjectId();
    }

    return new ObjectId($id);
}
