<?php

namespace StkSystemTest\MongoDB;

use MongoDB\BSON\Persistable;
use Stk\Immutable\Map;
use Stk\Immutable\Serialize\BSON;

class PersistableObject extends Map implements Persistable
{
    use BSON;

    public function __construct($data = [])
    {
        parent::__construct($data);
    }

    public function bsonSerialize()
    {
        return $this->_bsonSerialize($this->_data);
    }

    public function bsonUnserialize(array $data)
    {
        $this->_data = $this->_bsonUnserialize($data);
    }
}