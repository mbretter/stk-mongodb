<?php

namespace StkTest\MongoDB;

use ArrayIterator;
use DateTime;
use IteratorIterator;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stk\Immutable\Record;
use Stk\MongoDB\Connector;
use Traversable;

class ConntectorTest extends TestCase
{
    /** @var Client|MockObject */
    protected $client;

    /** @var Database|MockObject */
    protected $database;

    /** @var Collection|MockObject */
    protected $collection;

    /** @var InsertOneResult|MockObject */
    protected $insertOneResult;

    /** @var Connector */
    protected $connector;

    protected function setUp(): void
    {
        $this->database   = $this->createMock(Database::class);
        $this->client     = $this->createMock(Client::class);
        $this->collection = $this->createMock(Collection::class);

        $this->database->method('selectCollection')->willReturn($this->collection);

        $this->insertOneResult = $this->createMock(InsertOneResult::class);

        $this->connector = new Connector($this->database, 'users');
    }

    public function testNewId()
    {
        $objectId = $this->connector->newId();
        $this->assertInstanceOf(ObjectId::class, $objectId);
    }

    public function testNewIdWithString()
    {
        $oid      = new ObjectId();
        $objectId = $this->connector->newId((string)$oid);
        $this->assertEquals($oid, $objectId);
    }

    public function testSetDatabase()
    {
        $database = $this->createMock(Database::class);
        $database->expects($this->once())->method('selectCollection')->with('news');
        $this->collection->method('getCollectionName')->willReturn('news');
        $this->connector->setDatabase($database);
    }

    public function testGetDatabase()
    {
        $db = $this->connector->getDatabase();
        $this->assertSame($this->database, $db);
    }

    public function testSetCollection()
    {
        $this->database->expects($this->once())->method('selectCollection')->with('news');
        $this->connector->setCollection('news');
    }

    public function testGetCollection()
    {
        $coll = $this->connector->getCollection();
        $this->assertSame($this->collection, $coll);
    }

    // save

    public function testSaveNewRow()
    {
        $newOid = new ObjectId();
        $this->insertOneResult->method('getInsertedId')->willReturn($newOid);
        $this->collection->method('insertOne')->willReturn($this->insertOneResult);
        $row      = new Record();
        $savedRow = $this->connector->save($row);
        $this->assertEquals((string)$newOid, $savedRow->get('_id'));
    }

    public function testSaveExistingRow()
    {
        $oid      = new ObjectId();
        $row      = new Record([
            '_id' => $oid
        ]);
        $savedRow = $this->connector->save($row);
        $this->assertEquals((string)$oid, $savedRow->get('_id'));
    }

    // update

    public function testUpdateWithDateTime()
    {
        $dt  = new DateTime('2019-09-13 21:01:45');
        $oid = new ObjectId();
        $row = new Record([
            '_id'      => $oid,
            'somedate' => $dt
        ]);

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $oid],
            [
                '$set' => [
                    'somedate' => new UTCDateTime($dt)
                ]
            ],
            []
        );
        $this->connector->update($row);
    }

    public function testUpdateWithUpsert()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $oid],
            [
                '$set' => [
                    'foo'      => 'bar',
                    '__pclass' => new Binary(get_class($row), Binary::TYPE_USER_DEFINED)
                ]
            ],
            ['upsert' => true]
        );
        $this->connector->update($row, [], ['upsert' => true]);
    }

    public function testUpsert()
    {
        $oid      = new ObjectId();
        $row      = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);
        $criteria = [
            'foo2' => 'bar-foo'
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            $criteria,
            [
                '$set' =>
                    [
                        '__pclass' => new Binary(get_class($row), Binary::TYPE_USER_DEFINED),
                        'foo'      => 'bar'
                    ]
            ],
            ['upsert' => true]
        );
        $this->connector->upsert($criteria, $row);
    }

    public function testUpdateWithFields()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $fields = [
            '$set' => [
                'alice' => 'bob'
            ]
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $oid],
            [
                '$set' => [
                    'foo'   => 'bar',
                    'alice' => 'bob'
                ]
            ],
            []
        );
        $this->connector->update($row, $fields);
    }

    public function testUpdateWithFieldsOverwrite()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $fields = [
            '$set' => [
                'foo' => 'bar-foo'
            ]
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $oid],
            [
                '$set' => [
                    'foo' => 'bar'
                ]
            ],
            []
        );
        $this->connector->update($row, $fields);
    }

    public function testUpdateWithUnset()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $fields = [
            '$unset' => [
                'foo2' => 'bar-foo'
            ]
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $oid],
            [
                '$set'   => [
                    'foo' => 'bar'
                ],
                '$unset' => [
                    'foo2' => 'bar-foo'
                ]
            ],
            []
        );
        $this->connector->update($row, $fields);
    }

    public function testUpdateWithEmpty()
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->never())->method('updateOne');
        $res = $this->connector->update($row);
        $this->assertNull($res);
    }

    public function testUpdateWithCriteria()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $criteria = [
            'foo2' => 'bar-foo'
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            $criteria,
            [
                '$set' => [
                    'foo' => 'bar'
                ]
            ],
            []
        );
        $this->connector->update($row, [], [], $criteria);
    }

    public function testUpdateMany()
    {
        $criteria = [
            'foo2' => 'bar-foo'
        ];
        $fields   = [
            '$set' => [
                'foo' => 'bar'
            ]
        ];

        $this->collection->expects($this->once())->method('updateMany')->with(
            $criteria,
            $fields,
            []
        );
        $this->connector->updateMany($criteria, $fields, []);
    }

    public function testUpdateOne()
    {
        $criteria = [
            'foo2' => 'bar-foo'
        ];
        $fields   = [
            '$set' => [
                'foo' => 'bar'
            ]
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            $criteria,
            $fields,
            []
        );
        $this->connector->updateOne($criteria, $fields, []);
    }

    public function testBuildValueSetWithPrefix()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $set = $this->connector->buildValueSet($row, 'articles.$');
        $this->assertEquals([
            'articles.$.foo' => 'bar',
        ], $set);
    }

    // insert

    public function testInsert()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $this->collection->expects($this->once())->method('insertOne')->with($row);
        $this->connector->insert($row);
    }

    public function testInsertMany()
    {
        $oid  = new ObjectId();
        $row  = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);
        $rows = [
            $row,
            $row->set('_id', new ObjectId())
        ];

        $this->collection->expects($this->once())->method('insertMany')->with($rows);
        $this->connector->insertMany($rows);
    }

    // delete

    public function testDelete()
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid
        ]);

        $this->collection->expects($this->once())->method('deleteOne')->with(['_id' => $oid]);
        $this->connector->delete($row);
    }

    public function testDeleteById()
    {
        $oid = new ObjectId();

        $this->collection->expects($this->once())->method('deleteOne')->with(['_id' => $oid]);
        $this->connector->deleteById((string)$oid);
    }

    public function testDeleteMany()
    {
        $criteria = [
            'foo2' => 'bar-foo'
        ];

        $this->collection->expects($this->once())->method('deleteMany')->with(
            $criteria,
            []
        );
        $this->connector->deleteMany($criteria, []);
    }

    // find,fetch

    public function testFind()
    {
        $cursor = $this->createMock(Traversable::class);
        $this->collection->expects($this->once())->method('find')->with([], [])->willReturn($cursor);

        $ret = $this->connector->find();
        $this->assertInstanceOf(IteratorIterator::class, $ret);
    }

    public function testFindWithQueryId()
    {
        $oid    = new ObjectId();
        $cursor = $this->createMock(Traversable::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => $oid], [])->willReturn($cursor);

        $ret = $this->connector->find(['_id' => (string)$oid]);
        $this->assertInstanceOf(IteratorIterator::class, $ret);
    }

    public function testFetch()
    {
        $row1   = new Record(['_id' => new ObjectId()]);
        $row2   = $row1->set('_id', new ObjectId());
        $cursor = new IteratorIterator(new ArrayIterator([$row1, $row2]));
        $cursor->rewind();
        $ret = $this->connector->fetch($cursor);
        $this->assertSame($row1, $ret);
        $ret = $this->connector->fetch($cursor);
        $this->assertSame($row2, $ret);
        $ret = $this->connector->fetch($cursor);
        $this->assertNull($ret);
    }

    public function testFindOne()
    {
        $row = new Record(['_id' => new ObjectId()]);
        $this->collection->expects($this->once())->method('findOne')->with([], [])->willReturn($row);

        $ret = $this->connector->findOne();
        $this->assertSame($row, $ret);
    }

    public function testFindOneWithIdAsString()
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->once())->method('findOne')->with(['_id' => $oid], [])->willReturn($row);

        $ret = $this->connector->findOne((string)$oid);
        $this->assertSame($row, $ret);
    }

    public function testFindOneWithQuery()
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->once())->method('findOne')->with(['_id' => $oid], [])->willReturn($row);

        $ret = $this->connector->findOne(['_id' => $oid]);
        $this->assertSame($row, $ret);
    }

    // query

    public function testQuery()
    {
        $cursor = $this->createMock(Traversable::class);
        $this->collection->expects($this->once())->method('find')->with([], [])->willReturn($cursor);

        $ret = $this->connector->query();
        $this->assertInstanceOf(Traversable::class, $ret);
    }

    public function testQueryWithQueryId()
    {
        $oid    = new ObjectId();
        $cursor = $this->createMock(Traversable::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => $oid], [])->willReturn($cursor);

        $ret = $this->connector->query(['_id' => (string)$oid]);
        $this->assertInstanceOf(Traversable::class, $ret);
    }

    // count

    public function testCount()
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], [])->willReturn(10);

        $ret = $this->connector->count();
        $this->assertEquals(10, $ret);
    }

    public function testCountWithQuery()
    {
        $query = ['foo' => 'bar'];
        $this->collection->expects($this->once())->method('countDocuments')->with($query, [])->willReturn(5);

        $ret = $this->connector->count($query);
        $this->assertEquals(5, $ret);
    }

    public function testCountWithLimit()
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], ['limit' => 10])->willReturn(10);

        $ret = $this->connector->count([], 10);
        $this->assertEquals(10, $ret);
    }

    public function testCountWithSkip()
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], ['skip' => 10])->willReturn(5);

        $ret = $this->connector->count([], 0, 10);
        $this->assertEquals(5, $ret);
    }

    // next Seq


    public function testgGetNextSeq()
    {
        $this->collection->method('getCollectionName')->willReturn('news');

        $this->database->expects($this->once())->method('selectCollection')->with('sequence');
        $this->collection->expects($this->once())->method('findOneAndUpdate')->with(
            ['_id' => 'news'],
            ['$inc' => ['seq' => 1]],
            [
                'upsert'         => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        )->willReturn((object)['seq' => 1]);

        $ret = $this->connector->getNextSeq();
        $this->assertEquals(1, $ret);
    }

    public function testgGetNextSeqWithName()
    {
        $this->collection->expects($this->never())->method('getCollectionName');

        $this->database->expects($this->once())->method('selectCollection')->with('sequence');
        $this->collection->expects($this->once())->method('findOneAndUpdate')->with(
            ['_id' => 'mysequencename'],
            ['$inc' => ['seq' => 1]],
            [
                'upsert'         => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        )->willReturn((object)['seq' => 1]);

        $ret = $this->connector->getNextSeq('mysequencename');
        $this->assertEquals(1, $ret);
    }

    public function testgGetNextSeqWithSeqCollectionName()
    {
        $this->collection->method('getCollectionName')->willReturn('news');

        $this->database->expects($this->once())->method('selectCollection')->with('mycollectionname');
        $this->collection->expects($this->once())->method('findOneAndUpdate')->with(
            ['_id' => 'news'],
            ['$inc' => ['seq' => 1]],
            [
                'upsert'         => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        )->willReturn((object)['seq' => 1]);

        $ret = $this->connector->getNextSeq(null, 'mycollectionname');
        $this->assertEquals(1, $ret);
    }

    // gridfs

    public function testGridFS()
    {
        $this->database->expects($this->once())->method('selectGridFSBucket');
        $this->connector->gridFs();
    }

    // debug

    public function testDebug()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->connector->setLogger($logger);
        $logger->expects($this->once())->method('debug');
        $this->connector->findOne();
    }

}
