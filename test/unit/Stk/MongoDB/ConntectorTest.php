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
use MongoDB\DeleteResult;
use MongoDB\Driver\CursorInterface;
use MongoDB\GridFS\Bucket;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\UpdateResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stk\Immutable\Record;
use Stk\MongoDB\Connector;
use Traversable;

class ConntectorTest extends TestCase
{
    protected Client|MockObject $client;

    protected Database|MockObject $database;

    protected Collection|MockObject $collection;

    protected InsertOneResult|MockObject $insertOneResult;

    protected Connector $connector;

    protected function setUp(): void
    {
        $this->database   = $this->createMock(Database::class);
        $this->client     = $this->createMock(Client::class);
        $this->collection = $this->createMock(Collection::class);

        $this->database->method('selectCollection')->willReturn($this->collection);

        $this->insertOneResult = $this->createMock(InsertOneResult::class);

        $this->connector = new Connector($this->database, 'users');
    }

    public function testOId(): void
    {
        $objectId = Connector::oId();
        $this->assertInstanceOf(ObjectId::class, $objectId);
    }

    public function testOIdWithString(): void
    {
        $oid      = new ObjectId();
        $objectId = Connector::oId((string) $oid);
        $this->assertEquals($oid, $objectId);
    }

    public function testSetDatabase(): void
    {
        $database = $this->createMock(Database::class);
        $database->expects($this->once())
            ->method('selectCollection')
            ->with('news')
            ->willReturn($this->collection);
        $this->collection->method('getCollectionName')->willReturn('news');
        $this->connector->setDatabase($database);
    }

    public function testGetDatabase(): void
    {
        $db = $this->connector->getDatabase();
        $this->assertSame($this->database, $db);
    }

    public function testSetCollection(): void
    {
        $this->database->expects($this->once())
            ->method('selectCollection')
            ->with('news')
            ->willReturn($this->collection);
        $this->connector->setCollection('news');
    }

    public function testGetCollection(): void
    {
        $coll = $this->connector->getCollection();
        $this->assertSame($this->collection, $coll);
    }

    // save

    public function testSaveNewRow(): void
    {
        $newOid = new ObjectId();
        $this->insertOneResult->method('getInsertedId')->willReturn($newOid);
        $this->collection->method('insertOne')->willReturn($this->insertOneResult);
        $row      = new Record();
        $savedRow = $this->connector->save($row);
        $this->assertEquals((string) $newOid, $savedRow->get('_id'));
    }

    public function testSaveExistingRow(): void
    {
        $oid      = new ObjectId();
        $row      = new Record([
            '_id' => $oid
        ]);
        $savedRow = $this->connector->save($row);
        $this->assertEquals((string) $oid, $savedRow->get('_id'));
    }

    // update

    public function testUpdateWithDateTime(): void
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

    public function testUpdateWithUpsert(): void
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

    public function testUpsert(): void
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

    public function testUpdateWithFields(): void
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

    public function testUpdateWithFieldsOverwrite(): void
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

    public function testUpdateWithUnset(): void
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

    public function testUpdateWithEmpty(): void
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->never())->method('updateOne');
        $res = $this->connector->update($row);
        $this->assertNull($res);
    }

    public function testUpdateWithCriteria(): void
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

    public function testUpdateMany(): void
    {
        $criteria = [
            'foo2' => 'bar-foo'
        ];
        $fields   = [
            '$set' => [
                'foo' => 'bar'
            ]
        ];

        $result = $this->createMock(UpdateResult::class);

        $this->collection->expects($this->once())->method('updateMany')->with(
            $criteria,
            $fields,
            []
        )->willReturn($result);

        $this->connector->updateMany($criteria, $fields, []);
    }

    public function testUpdateOne(): void
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
        )->willReturn($this->createMock(UpdateResult::class));
        $this->connector->updateOne($criteria, $fields, []);
    }

    public function testBuildValueSetWithPrefix(): void
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

    public function testUpdateWithIntId(): void
    {
        $id  = 234;
        $row = new Record([
            '_id' => $id,
            'foo' => 'bar'
        ]);

        $fields = [
            '$set' => [
                'alice' => 'bob'
            ]
        ];

        $this->collection->expects($this->once())->method('updateOne')->with(
            ['_id' => $id],
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

    // insert

    public function testInsert(): void
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid,
            'foo' => 'bar'
        ]);

        $this->collection->expects($this->once())
            ->method('insertOne')
            ->with($row)
            ->willReturn($this->insertOneResult);
        $this->connector->insert($row);
    }

    public function testInsertMany(): void
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

        $this->collection->expects($this->once())
            ->method('insertMany')
            ->with($rows)
            ->willReturn($this->createMock(InsertManyResult::class));
        $this->connector->insertMany($rows);
    }

    // delete

    public function testDelete(): void
    {
        $oid = new ObjectId();
        $row = new Record([
            '_id' => $oid
        ]);

        $this->collection->expects($this->once())
            ->method('deleteOne')
            ->with(['_id' => $oid])
            ->willReturn($this->createMock(DeleteResult::class));
        $this->connector->delete($row);
    }

    public function testDeleteById(): void
    {
        $oid = new ObjectId();

        $this->collection->expects($this->once())
            ->method('deleteOne')
            ->with(['_id' => $oid])
            ->willReturn($this->createMock(DeleteResult::class));
        $this->connector->deleteById((string) $oid);
    }

    public function testDeleteByIntId(): void
    {
        $id = 99;

        $this->collection->expects($this->once())
            ->method('deleteOne')
            ->with(['_id' => $id])
            ->willReturn($this->createMock(DeleteResult::class));
        $this->connector->deleteById($id);
    }

    public function testDeleteMany(): void
    {
        $criteria = [
            'foo2' => 'bar-foo'
        ];

        $this->collection->expects($this->once())->method('deleteMany')->with(
            $criteria,
            []
        )->willReturn($this->createMock(DeleteResult::class));
        $this->connector->deleteMany($criteria, []);
    }

    // find,fetch

    public function testFind(): void
    {
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with([], [])->willReturn($cursor);

        $ret = $this->connector->find();
        $this->assertInstanceOf(IteratorIterator::class, $ret);
    }

    public function testFindWithQueryId(): void
    {
        $oid    = new ObjectId();
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => $oid], [])->willReturn($cursor);

        $ret = $this->connector->find(['_id' => (string) $oid]);
        $this->assertInstanceOf(IteratorIterator::class, $ret);
    }

    public function testFindWithIntId(): void
    {
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => 12], [])->willReturn($cursor);

        $ret = $this->connector->find(['_id' => 12]);
        $this->assertInstanceOf(IteratorIterator::class, $ret);
    }

    public function testFetch(): void
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

    public function testFindOne(): void
    {
        $row = new Record(['_id' => new ObjectId()]);
        $this->collection->expects($this->once())->method('findOne')->with([], [])->willReturn($row);

        $ret = $this->connector->findOne();
        $this->assertSame($row, $ret);
    }

    public function testFindOneWithIdAsString(): void
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->once())->method('findOne')->with(['_id' => $oid], [])->willReturn($row);

        $ret = $this->connector->findOne((string) $oid);
        $this->assertSame($row, $ret);
    }

    public function testFindOneWithIntId(): void
    {
        $id  = 22;
        $row = new Record(['_id' => $id]);
        $this->collection->expects($this->once())->method('findOne')->with(['_id' => $id], [])->willReturn($row);

        $ret = $this->connector->findOne($id);
        $this->assertSame($row, $ret);
    }

    public function testFindOneWithQuery(): void
    {
        $oid = new ObjectId();
        $row = new Record(['_id' => $oid]);
        $this->collection->expects($this->once())->method('findOne')->with(['_id' => $oid], [])->willReturn($row);

        $ret = $this->connector->findOne(['_id' => $oid]);
        $this->assertSame($row, $ret);
    }

    // query

    public function testQuery(): void
    {
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with([], [])->willReturn($cursor);

        $ret = $this->connector->query();
        $this->assertInstanceOf(Traversable::class, $ret);
    }

    public function testQueryWithQueryId(): void
    {
        $oid    = new ObjectId();
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => $oid], [])->willReturn($cursor);

        $ret = $this->connector->query(['_id' => (string) $oid]);
        $this->assertInstanceOf(Traversable::class, $ret);
    }

    public function testQueryWithQueryWithIntId(): void
    {
        $id     = 66;
        $cursor = $this->createMock(CursorInterface::class);
        $this->collection->expects($this->once())->method('find')->with(['_id' => $id], [])->willReturn($cursor);

        $ret = $this->connector->query(['_id' => 66]);
        $this->assertInstanceOf(Traversable::class, $ret);
    }

    // count

    public function testCount(): void
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], [])->willReturn(10);

        $ret = $this->connector->count();
        $this->assertEquals(10, $ret);
    }

    public function testCountWithQuery(): void
    {
        $query = ['foo' => 'bar'];
        $this->collection->expects($this->once())->method('countDocuments')->with($query, [])->willReturn(5);

        $ret = $this->connector->count($query);
        $this->assertEquals(5, $ret);
    }

    public function testCountWithLimit(): void
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], ['limit' => 10])->willReturn(10);

        $ret = $this->connector->count([], 10);
        $this->assertEquals(10, $ret);
    }

    public function testCountWithSkip(): void
    {
        $this->collection->expects($this->once())->method('countDocuments')->with([], ['skip' => 10])->willReturn(5);

        $ret = $this->connector->count([], 0, 10);
        $this->assertEquals(5, $ret);
    }

    // next Seq


    public function testgGetNextSeq(): void
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
        )->willReturn((object) ['seq' => 1]);

        $ret = $this->connector->getNextSeq();
        $this->assertEquals(1, $ret);
    }

    public function testgGetNextSeqWithName(): void
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
        )->willReturn((object) ['seq' => 1]);

        $ret = $this->connector->getNextSeq('mysequencename');
        $this->assertEquals(1, $ret);
    }

    public function testgGetNextSeqWithSeqCollectionName(): void
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
        )->willReturn((object) ['seq' => 1]);

        $ret = $this->connector->getNextSeq(null, 'mycollectionname');
        $this->assertEquals(1, $ret);
    }

    public function testGetNextSeqWithFilter(): void
    {
        $this->collection->method('getCollectionName')->willReturn('news');

        $this->database->expects($this->once())->method('selectCollection')->with('sequence');
        $this->collection->expects($this->once())->method('findOneAndUpdate')->with(
            [
                '_id' => 'news',
                'foo' => 'bar'
            ],
            ['$inc' => ['seq' => 1]],
            [
                'upsert'         => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        )->willReturn((object) ['seq' => 1]);

        $ret = $this->connector->getNextSeq(null, null, ['foo' => 'bar']);
        $this->assertEquals(1, $ret);
    }

    // gridfs

    public function testGridFS(): void
    {
        $this->database->expects($this->once())
            ->method('selectGridFSBucket')
            ->willReturn($this->createMock(Bucket::class));
        $this->connector->gridFs();
    }

    // debug

    public function testDebug(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->connector->setLogger($logger);
        $logger->expects($this->once())->method('debug');
        $this->connector->findOne();
    }

}
