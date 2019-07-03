<?php

namespace Stk\MongoDB;

use DateTime;
use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDatetime;
use MongoDB\Driver\Cursor;
use MongoDB\GridFS\Bucket;
use MongoDB\InsertManyResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\Database;
use MongoDB\Collection;
use MongoDB\UpdateResult;
use Psr\Log\LoggerAwareTrait;
use Stk\Immutable\ImmutableInterface;
use Stk\Service\Injectable;

class Connector implements Injectable
{
    use LoggerAwareTrait;

    /** @var  Database */
    protected $_database;

    /** @var  Collection */
    protected $_collection;

    /**
     *
     * @param Database $mongodb
     * @param string $collectionName
     */
    public function __construct(Database $mongodb, string $collectionName)
    {
        $this->_database   = $mongodb;
        $this->_collection = $mongodb->selectCollection($collectionName);
    }

    public function setDatabase(Database $mongodb)
    {
        $coll              = $this->_collection->getCollectionName();
        $this->_database   = $mongodb;
        $this->_collection = $mongodb->selectCollection($coll);
    }

    public function getDatabase()
    {
        return $this->_database;
    }

    public function setCollection($collection)
    {
        $this->_collection = $this->_database->selectCollection($collection);

        return $this;
    }

    /**
     * @return  Collection
     */
    public function getCollection()
    {
        return $this->_collection;
    }

    public function newId($id = null)
    {
        if ($id === null) {
            return new ObjectId();
        }

        return new ObjectId($id);
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
    public function save(ImmutableInterface $row, $fields = [], $options = [])
    {
        if ($row->get('_id')) {
            $this->update($row, $fields, $options);

            return $row;
        } else {
            $insertResult = $this->insert($row, $options);
            if ($insertResult->getInsertedId() instanceof ObjectId) {
                return $row->set('_id', (string)$insertResult->getInsertedId());
            }
        }
    }

    public function insert(ImmutableInterface $row, $options = [])
    {
        $this->debug(__METHOD__ . ":" . print_r($row, true));

        return $this->_collection->insertOne($row, $options);
    }

    /**
     * @param ImmutableInterface[] $rows
     * @param array $options
     *
     * @return InsertManyResult
     */
    public function insertMany($rows, $options = [])
    {
        $this->debug(__METHOD__ . ":" . print_r($rows, true));

        return $this->_collection->insertMany($rows, $options);
    }

    /**
     * @param ImmutableInterface $row
     * @param array $fields
     * @param array $options
     *
     * @param null $criteria
     *
     * @return UpdateResult
     */
    public function update(ImmutableInterface $row, $fields = [], $options = [], $criteria = null)
    {
        $values = [];
        $row->walk(function ($path, $value) use (&$values) {
            if ($path[0] == '_id') {
                return;
            }

            if ($value instanceof DateTime) {
                $value = new UTCDatetime($value);
            }

            $values[implode('.', $path)] = $value;
        });

        if (isset($options['upsert'])) {
            // manually set __pclass, otherwise __pclass will not be set with upserts
            $values['__pclass'] = new Binary(get_class($row), Binary::TYPE_USER_DEFINED);
        }

        $setfields = [];
        if (count($values)) {
            $setfields = ['$set' => $values];
        }

        $fields = array_merge($setfields, $fields);

        if (!count($fields)) {
            $this->debug(__METHOD__ . ':nothing to update');

            return null;
        }

        if ($criteria === null) {
            $criteria = ['_id' => new ObjectId($row->get('_id'))];
        }

        $this->debug(__METHOD__ . ':' . $this->_collection->getCollectionName() . ':' . print_r($criteria, true) . ':' . print_r($fields, true));

        return $this->_collection->updateOne($criteria, $fields, $options);
    }

    public function updateMany($query = [], $fields = [], $options = [])
    {
        return $this->_collection->updateMany($query, $fields, $options);
    }

    public function delete(ImmutableInterface $row)
    {
        return $this->deleteById($row->get('_id'));
    }

    public function deleteById($id)
    {
        $this->debug(__METHOD__ . ":$id");

        return $this->_collection->deleteOne(['_id' => new ObjectId($id)]);
    }

    public function deleteMany($query = [], $options = [])
    {
        return $this->_collection->deleteMany($query, $options);
    }

    /**
     * @param array $criteria
     * @param ImmutableInterface $row
     * @param array $fields
     * @param array $options
     *
     * @return UpdateResult
     */
    public function upsert($criteria, ImmutableInterface $row, $fields = [], $options = [])
    {
        $options['upsert'] = true;

        return $this->update($row, $fields, $options, $criteria);
    }

    /**
     * IterartorIterator can be traversed using fetch(), usable when migrating from the legacy mongodb driver
     * may also be used, if traversing the result set more then once
     *
     * @param array $query
     * @param array $options
     *
     * @return IteratorIterator
     */
    public function find($query = [], $options = [])
    {
        if (array_key_exists('_id', $query) && is_string($query['_id'])) {
            $query['_id'] = new ObjectId($query['_id']);
        }

        $this->debug(__METHOD__ . ":" . $this->_collection . ":" . print_r($query, true) . ' options:' . print_r($options, true));

        $cursor = $this->_collection->find($query, $options);
        $it     = new IteratorIterator($cursor);
        $it->rewind();

        return $it;
    }

    /**
     * @param $cursor IteratorIterator
     *
     * @return null|ImmutableInterface
     */
    public function fetch($cursor)
    {
        $o = $cursor->current();
        if ($o === null) {
            return $o;
        }

        $cursor->next();

        return $o;
    }

    /**
     * returns a traversable cursor result can be directly used with foreach
     * cursor can only traversed once
     *
     * @param array $query
     * @param array $options
     *
     * @return Cursor|ImmutableInterface[]
     */
    public function query($query = [], $options = [])
    {
        if (array_key_exists('_id', $query) && is_string($query['_id'])) {
            $query['_id'] = new ObjectId($query['_id']);
        }

        return $this->_collection->find($query, $options);
    }

    /**
     * @param array $query
     * @param array $fields
     *
     * @return array|object|null|ImmutableInterface
     */
    public function findOne($query = [], $fields = [])
    {
        if (is_string($query)) {
            $id    = $query;
            $query = ['_id' => new ObjectId($id)];
        }

        $this->debug(__METHOD__ . ":" . $this->_collection . ":" . print_r($query, true));

        return $this->_collection->findOne($query, $fields);
    }

    /**
     * @param array $query
     * @param int $limit
     * @param int $skip
     *
     * @return int
     */
    public function count($query = [], $limit = 0, $skip = 0)
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
     * @param null $name the name of the sequence, defaults to the current selected collection name
     * @param string $seqCollection the collection holding the sequence values
     *
     * @return mixed
     */
    public function getNextSeq($name = null, $seqCollection = 'sequence')
    {
        if ($name === null) {
            $name = $this->_collection->getCollectionName();
        }

        $coll = $this->_database->selectCollection($seqCollection);
        $ret  = $coll->findOneAndUpdate(['_id' => $name], ['$inc' => ['seq' => 1]], [
            'upsert'         => true,
            'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
        ]);

        return $ret->seq;
    }

    /**
     * @return Bucket
     */
    public function gridFs()
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
    public function debug($message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->debug($message, $context);
        }

    }
}

