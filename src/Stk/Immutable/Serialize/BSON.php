<?php

namespace Stk\Immutable\Serialize;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use DateTime;
use DateTimeZone;
use stdClass;

/**
 * BSON serialization and vice versa
 * We must convert UTCDateTime and ObjectIDs, because they are not cloneable
 * this would break the immutability
 *
 */
trait BSON
{
    private function _bsonSerialize($data)
    {
        if (is_object($data) && $data instanceof stdClass) {
            $ovs = get_object_vars($data);

            // keep empty stdClasses
            if (!count($ovs)) {
                return $data;
            }

            $data = $ovs;
        }

        if (is_array($data)) {
            return array_map([$this, '_bsonSerialize'], $data);
        } else {
            if ($data instanceof DateTime) {
                $data = new UTCDatetime($data);
            }

            return $data;
        }
    }

    private function _bsonUnserialize(array $data)
    {
        array_walk_recursive($data, function (&$v, $k) {
            if ($v instanceof UTCDateTime) {
                $v = $v->toDateTime()->setTimezone(new DateTimeZone(date_default_timezone_get()));
            } elseif ($v instanceof ObjectID) {
                $v = (string)$v;
            }
        });

        // remove this attribute it's not cloneable
        // it will be autom. added by Connector when saving the data
        unset($data['__pclass']);

        return $data;
    }
}
