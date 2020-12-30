<?php

namespace StkSystemTest\MongoDB;

use DateTime;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use PHPUnit\Framework\TestCase;
use Stk\Immutable\Ops\Diff;
use Stk\MongoDB\Connector;
use Stk\MongoDB\SessionManager;

/*
 * mongodb testing user:
    db.createUser({ user: "stk-testing", pwd: "1qay2wsx",
        roles: [ { role: "readWrite", db: "stk-tests" }, {role: "dbAdmin", db: "stk-tests"} ]
    });
 *
 */

class SessionManagerTest extends TestCase
{
    protected Database $database;
    protected Collection $collection;

    protected function setUp(): void
    {
        $mc = new Client('mongodb://stk-testing:1qay2wsx@127.0.0.1/stk-tests');

        $this->database = $mc->selectDatabase('stk-tests');

        $this->collection = $this->database->selectCollection('sessions');
        $this->collection->createIndex(["expires" => 1], ["expireAfterSeconds" => 0]);
        $manager = new SessionManager($this->collection);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public function testWrite()
    {
        $this->collection->deleteMany([]);
        session_start();
        $_SESSION['foo'] = 'bar';
        session_write_close();
        $row = $this->collection->findOne();
        $this->assertEquals('foo|s:3:"bar";', $row['data']);
    }

    public function testRead()
    {
        $this->collection->deleteMany([]);
        $sid = "1gcgpiuul446q60tmicdioec9g";
        $this->collection->insertOne([
            "_id"     => $sid,
            "data"    => 'foo|s:3:"bar";',
            "expires" => new UTCDateTime((time() + 10) * 1000)
        ]);
        session_id($sid);
        session_start();
        $this->assertArrayHasKey("foo", $_SESSION);
        $this->assertEquals("bar", $_SESSION['foo']);
    }

    public function testReadExpired()
    {
        $this->collection->deleteMany([]);
        $sid = "1gcgpiuul446q60tmicdioec9g";
        $this->collection->insertOne([
            "_id"     => $sid,
            "data"    => 'foo|s:3:"bar";',
            "expires" => new UTCDateTime((time() + -10) * 1000)
        ]);
        session_id($sid);
        session_start();
        $this->assertArrayNotHasKey("foo", $_SESSION);
    }

    public function testDestroy()
    {
        $this->collection->deleteMany([]);
        $sid = "1gcgpiuul446q60tmicdioec9g";
        $this->collection->insertOne([
            "_id"     => $sid,
            "data"    => 'foo|s:3:"bar";',
            "expires" => new UTCDateTime((time() + -10) * 1000)
        ]);
        session_id($sid);
        session_start();
        session_destroy();
        $row = $this->collection->findOne();
        $this->assertNull($row);
    }

    public function testGc()
    {
        $this->collection->deleteMany([]);
        $this->collection->insertOne([
            "_id"     => "1gcgpiuul446q60tmicdioec9g",
            "data"    => 'foo|s:3:"bar";',
            "expires" => new UTCDateTime((time() + -10) * 1000)
        ]);
        session_start();
        session_gc();
        $row = $this->collection->findOne();
        $this->assertNull($row);
    }
}
