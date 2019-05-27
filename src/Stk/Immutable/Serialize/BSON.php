<?php

namespace Stk\Immutable\Serialize;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use DateTime;
use DateTimeZone;

trait BSON
{
    public function bsonSerialize(array $data)
    {
        array_walk_recursive($data, function (&$v, $k) {
            if ($v instanceof DateTime) {
                $v = new UTCDatetime($v);
            }
        });

        $_id = $data['_id'];
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

        return $data;
    }
}
