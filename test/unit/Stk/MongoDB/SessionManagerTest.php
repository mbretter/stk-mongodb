<?php

namespace StkTest\MongoDB;

use Exception;
use phpmock\Mock;
use phpmock\phpunit\PHPMock;
use MongoDB\BSON\UTCDateTime as MongoDate;
use MongoDB\Collection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stk\MongoDB\SessionManager;

class SessionManagerTest extends TestCase
{
    use PHPMock;

    /** @var Collection|MockObject */
    protected $collection;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);

        $timeMock1 = new Mock('Stk\MongoDB', "time",
            function () {
                return 1;
            }
        );
        $timeMock1->enable();

        $timeMock2 = new Mock('StkTest\MongoDB', "time",
            function () {
                return 1;
            }
        );
        $timeMock2->enable();

        $this->getFunctionMock('Stk\MongoDB', 'session_set_save_handler');
    }

    public function testRead()
    {
        $this->collection->expects($this->once())
            ->method("findOne")
            ->with([
                '_id'     => "foo",
                'expires' => ['$gt' => new MongoDate(time() * 1000)]
            ], ['typeMap' => ['root' => 'array']])
            ->willReturn([
                "data" => "foo"
            ]);

        $manager = new SessionManager($this->collection);
        $res     = $manager->read("foo");
        $this->assertEquals("foo", $res);
    }

    public function testReadWithException()
    {
        $this->collection->expects($this->once())
            ->method("findOne")
            ->willThrowException(new Exception("something went wrong"));

        $manager = new SessionManager($this->collection);
        $res     = $manager->read("foo");
        $this->assertEquals("", $res);
    }

    public function testWrite()
    {
        $sid  = "foo";
        $data = 'foo|s:3:"bar";';
        $this->collection->expects($this->once())
            ->method("replaceOne")
            ->with(
                [
                    '_id' => $sid
                ],
                [
                    '_id'     => $sid,
                    'data'    => $data,
                    'expires' => new MongoDate(1000 * (time() + SessionManager::DEFAULT_TIMEOUT))
                ],
                [
                    'upsert' => true
                ]);

        $manager = new SessionManager($this->collection);
        $res     = $manager->write($sid, $data);
        $this->assertTrue($res);
    }

    public function testWriteWithException()
    {
        $sid  = "foo";
        $data = 'foo|s:3:"bar";';
        $this->collection->expects($this->once())
            ->method("replaceOne")
            ->willThrowException(new Exception("something went wrong"));

        $manager = new SessionManager($this->collection);
        $res     = $manager->write($sid, $data);
        $this->assertFalse($res);
    }
}
