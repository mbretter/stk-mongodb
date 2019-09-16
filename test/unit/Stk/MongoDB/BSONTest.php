<?php

namespace StkTest\MongoDB;

use DateTime;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stk\Immutable\Map;
use Stk\Immutable\Serialize\BSON;


class BSONTest extends TestCase
{

    public function testSerializeWithId()
    {
        $a = new BSONData((object)['_id' => '5c49d90ffbab771ca667abe1', 'x' => 'foo', 'y' => 'bar']);
        $this->assertEquals([
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => 'bar',
        ], $a->bsonSerialize());

        $b = new BSONData((object)['x' => 'foo', 'y' => 'bar', 'z' => (object)['_id' => '5c49d90ffbab771ca667abe1']]);
        $this->assertEquals([
            'x' => 'foo',
            'y' => 'bar',
            'z' => [
                '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            ]
        ], $b->bsonSerialize());

    }

    public function testSerializeWithInlineId()
    {
        $a = new BSONData((object)[
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => [
                'ref_id' => '5c49d90ffbab771ca667abe2'
            ]
        ]);

        $this->assertEquals([
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => [
                'ref_id' => '5c49d90ffbab771ca667abe2'
            ],
        ], $a->bsonSerialize());
    }

    public function testSerializeWithDateTime()
    {
        $a = new BSONData((object)[
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => new DateTime('2022-01-06 12:44:56')
        ]);

        $this->assertEquals([
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => new UTCDateTime('1641473096000'),
        ], $a->bsonSerialize());
    }

    public function testSerializeWithNestedDateTime()
    {
        $a = new BSONData((object)[
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => [
                'a' => new DateTime('2022-01-06 12:44:56')
            ]
        ]);

        $this->assertEquals([
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => [
                'a' => new UTCDateTime('1641473096000')
            ]
        ], $a->bsonSerialize());
    }

    public function testSerializeWithEmptyStdClass()
    {
        $a = new BSONData((object)[
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => [
                'ref_id' => new stdClass()
            ]
        ]);

        $this->assertEquals([
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => [
                'ref_id' => new stdClass()
            ],
        ], $a->bsonSerialize());
    }

    public function testUnserialize()
    {
        $data = [
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => [
                'ref_id' => new ObjectId('5c49d90ffbab771ca667abe2')
            ],
        ];

        $a = new BSONData();
        $a->bsonUnserialize($data);

        $this->assertEquals([
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => [
                'ref_id' => '5c49d90ffbab771ca667abe2'
            ],
        ], $a->get());
    }

    public function testUnserializeWithDateTime()
    {
        $data = [
            '_id' => new ObjectId('5c49d90ffbab771ca667abe1'),
            'x'   => 'foo',
            'y'   => [
                'dt' => new UTCDateTime('1641473096000')
            ],
        ];

        $a = new BSONData();
        $a->bsonUnserialize($data);

        $this->assertEquals([
            '_id' => '5c49d90ffbab771ca667abe1',
            'x'   => 'foo',
            'y'   => [
                'dt' => new DateTime('2022-01-06 12:44:56')
            ],
        ], $a->get());
    }
}

class BSONData extends Map implements Persistable
{
    use BSON;

    public function bsonSerialize()
    {
        return $this->_bsonSerialize($this->_data);
    }

    public function bsonUnserialize(array $data)
    {
        $this->_data = $this->_bsonUnserialize($data);
    }
}