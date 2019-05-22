<?php

namespace Stk\MongoDB;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Persistable;
use MongoDB\BSON\UTCDateTime;
use DateTime;
use DateTimeZone;
use Stk\Immutable\Additions\ToArray;
use Stk\Immutable\Immutable;

class BSON implements Persistable
{
    use Immutable;
    use ToArray;

    public function __construct($data = null)
    {
        $this->_data = $data;
    }


    public function bsonSerialize()
    {
        $data = $this->dataToArray($this->_data);

        array_walk_recursive($data, function (&$v, $k) {
            if ($v instanceof DateTime) {
                $v = new UTCDatetime($v->getTimestamp() * 1000);
            }
        });

        $_id = $this->get('_id');
        if (is_string($_id) && strlen($_id)) {
            $data['_id'] = new ObjectId($_id);
        }

        return $data;
    }

    public function bsonUnserialize(array $data)
    {
        array_walk_recursive($data, function (&$v, $k) {
            if ($v instanceof UTCDateTime) {
                $v = $v->toDateTime()->setTimezone(new DateTimeZone(date_default_timezone_get()));
            } elseif ($v instanceof ObjectId) {
                $v = (string)$v;
            }
        });

        $this->_data = $data;
    }
}
