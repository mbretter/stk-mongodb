<?php

namespace StkSystemTest\MongoDB;

use DateTime;
use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\TestCase;
use Stk\Immutable\Ops\Diff;
use Stk\MongoDB\Connector;

/*
 * mongodb testing user:
    db.createUser({ user: "stk-testing", pwd: "1qay2wsx",
        roles: [ { role: "readWrite", db: "stk-tests" }, {role: "dbAdmin", db: "stk-tests"} ]
    });
 *
 */

class ConnectorTest extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        $mc = new Client('mongodb://stk-testing:1qay2wsx@127.0.0.1/stk-tests');

        $this->database = $mc->selectDatabase('stk-tests');
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public function testCRUDSimple(): void
    {
        $conn = new Connector($this->database, 'test1');
        $data = new PersistableObject([
            'foo' => 'bar'
        ]);

        $result = $conn->insert($data);

        $this->assertEquals(1, $result->getInsertedCount());

        $row = $conn->findOne((string)$result->getInsertedId());

        $this->assertInstanceOf(PersistableObject::class, $row);
        $this->assertEquals('bar', $row->get('foo'));

        $new = $row->set('name', 'alice');

        $modified = (new Diff)($row, $new);
        $modified = $modified->set('_id', $row->get('_id'));

        $result = $conn->update($modified);
        $this->assertEquals(1, $result->getModifiedCount());
        $this->assertEquals(1, $result->getMatchedCount());

        $result = $conn->delete($row);
        $this->assertEquals(1, $result->getDeletedCount());
    }

    public function testSaveWithInsert(): void
    {
        $conn = new Connector($this->database, 'test1');
        $data = new PersistableObject((object)['a' => 1234, 'b' => 876]);

        $saved = $conn->save($data);

        $this->assertInstanceOf(PersistableObject::class, $saved);
        $this->assertIsString($saved->get('_id'));
        $this->assertNotSame($data, $saved);
    }

    public function testSaveWithUpdate(): void
    {
        $conn = new Connector($this->database, 'test1');
        $data = new PersistableObject((object)['a' => 1234, 'b' => 876]);

        $inserted = $conn->save($data);

        $row = $conn->findOne((string)$inserted->get('_id'));

        $this->assertInstanceOf(PersistableObject::class, $row);

        $modified = $row->set('c', 1234)->set('a', 'foo');
        $saved    = $conn->save($modified);

        $this->assertSame($modified, $saved);

        $row = $conn->findOne((string)$inserted->get('_id'));

        $this->assertInstanceOf(PersistableObject::class, $row);

        $this->assertEquals('foo', $row->get('a'));
        $this->assertEquals(876, $row->get('b'));
        $this->assertEquals(1234, $row->get('c'));
    }

    public function testDateTime(): void
    {
        $dateTime = new DateTime('@' . time()); // avoid usecs, which is not supported by mongodb
        $conn     = new Connector($this->database, 'test1');
        $data     = new PersistableObject([
            'created' => $dateTime
        ]);

        $result = $conn->insert($data);

        $this->assertEquals(1, $result->getInsertedCount());

        $row = $conn->findOne((string)$result->getInsertedId());

        $this->assertInstanceOf(PersistableObject::class, $row);
        $this->assertEquals($row->get('created'), $dateTime);
    }

    public function testId(): void
    {
        $conn = new Connector($this->database, 'test1');
        $data = new PersistableObject();

        $result = $conn->insert($data);

        $this->assertEquals(1, $result->getInsertedCount());

        $row = $conn->findOne((string)$result->getInsertedId());

        $this->assertInstanceOf(PersistableObject::class, $row);
        $this->assertIsString($row->get('_id'));
    }

}
