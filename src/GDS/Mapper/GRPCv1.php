<?php
/**
 * Copyright 2015 Tom Walder
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace GDS\Mapper;

use GDS\Entity;
use GDS\Property\Geopoint;
use GDS\Schema;
use Google\Type\LatLng;
use Google\Cloud\Datastore\V1\Entity as GRPC_Entity;
use Google\Cloud\Datastore\V1\EntityResult as GRPC_EntityResult;

/**
 * gRPC v1 Mapper
 * based on Protobuf Mapper by Tom Walder <twalder@gmail.com>
 *
 * @author Samuel Melrose <sam@infitialis.com>
 * @author Tom Walder <twalder@gmail.com>
 */
class GRPCv1 extends \GDS\Mapper
{

    private $partitionId;

    public function setPartitionId($var)
    {
        $this->partitionId = $var;
    }

    /**
     * Map from GDS to Google Protocol Buffer
     *
     * @param Entity $obj_gds_entity
     * @param Google\Cloud\Datastore\V1\Entity $obj_entity
     */
    public function mapToGoogle(Entity $obj_gds_entity, GRPC_Entity $obj_entity)
    {
        // Key
        $obj_entity->setKey($this->createGoogleKey($obj_gds_entity));

        // Properties
        $props = [];
        $arr_field_defs = $this->obj_schema->getProperties();
        foreach($obj_gds_entity->getData() as $str_field_name => $mix_value) {
            if(isset($arr_field_defs[$str_field_name])) {
                $props[$str_field_name] = $this->configureGooglePropertyValue($arr_field_defs[$str_field_name], $mix_value);
            } else {
                $arr_dynamic_data = $this->determineDynamicType($mix_value);
                $props[$str_field_name] = $this->configureGooglePropertyValue(['type' => $arr_dynamic_data['type'], 'index' => TRUE], $arr_dynamic_data['value']);
            }
        }

        $obj_entity->setProperties($props);
    }

    /**
     * Map a single result out of the Raw response data into a supplied Entity object
     *
     * @todo Validate dynamic schema mapping in multi-kind responses like fetchEntityGroup()
     *
     * @param EntityResult $obj_result
     * @return Entity
     */
    public function mapOneFromResult(GRPC_EntityResult $obj_result)
    {
        // Key & Ancestry
        list($obj_gds_entity, $bol_schema_match) = $this->createEntityWithKey($obj_result);

        // Properties
        $arr_property_definitions = $this->obj_schema->getProperties();
        foreach($obj_result->getEntity()->getProperties() as $str_field => $obj_property) {
            /* string => Google\Cloud\Datastore\V1\Value */
            if ($bol_schema_match && isset($arr_property_definitions[$str_field])) {
                $obj_gds_entity->__set($str_field, $this->extractPropertyValue($arr_property_definitions[$str_field]['type'], $obj_property->getValue()));
            } else {
                $obj_gds_entity->__set($str_field, $this->extractPropertyValue(Schema::PROPERTY_DETECT, $obj_property->getValue()));
            }
        }
        return $obj_gds_entity;
    }

    /**
     * Create & populate a GDS\Entity with key data
     *
     * @todo Validate dynamic mapping
     *
     * @param EntityResult $obj_result
     * @return array
     */
    private function createEntityWithKey(GRPC_EntityResult $obj_result)
    {
        // Get the full key path
        $arr_key_path = $obj_result->getEntity()->getKey()->getPath();

        // Key for 'self' (the last part of the KEY PATH)
        /* @var $obj_path_end \google\appengine\datastore\v4\Key\PathElement */
        $obj_path_end = array_pop($arr_key_path);
        if($obj_path_end->getKind() == $this->obj_schema->getKind()) {
            $bol_schema_match = TRUE;
            $obj_gds_entity = $this->obj_schema->createEntity();
        } else {
            $bol_schema_match = FALSE;
            $obj_gds_entity = (new \GDS\Entity())->setKind($obj_path_end->getKind());
        }

        // Set ID or Name (will always have one or the other)
        if($obj_path_end->getIdType() == 'id') {
            $obj_gds_entity->setKeyId($obj_path_end->getId());
        } else {
            $obj_gds_entity->setKeyName($obj_path_end->getName());
        }

        // Ancestors?
        $int_ancestor_elements = count($arr_key_path);
        if($int_ancestor_elements > 0) {
            $arr_anc_path = [];
            foreach ($arr_key_path as $obj_kpe) {
                $arr_anc_path[] = [
                    'kind'  => $obj_kpe->getKind(),
                    'id'    => ($obj_kpe->getIdType() == 'id') ? $obj_kpe->getId() : null,
                    'name'  => ($obj_kpe->getIdType() == 'name') ? $obj_kpe->getName() : null
                ];
            }
            $obj_gds_entity->setAncestry($arr_anc_path);
        }

        // Return whether or not the Schema matched
        return [$obj_gds_entity, $bol_schema_match];
    }

    /**
     * Populate a ProtoBuf Key from a GDS Entity
     *
     * @param Key $obj_key
     * @param Entity $obj_gds_entity
     * @return Key
     */
    public function createGoogleKey(Entity $obj_gds_entity)
    {
        $obj_key = new Key();
        $obj_key->setPartitionId($this->partitionId);

        $path = $this->walkGoogleKeyPathElement([], $obj_gds_entity);

        $obj_key->setPath($path);

        return $obj_key;
    }

    public function walkGoogleKeyPathElement($path, Entity $obj_gds_entity)
    {
        // Root Key (must be the first in the chain)
        $path = $this->prependGoogleKeyPathElement($path, $obj_gds_entity);

        // Add any ancestors
        $mix_ancestry = $obj_gds_entity->getAncestry();
        if(is_array($mix_ancestry)) {
            // @todo Get direction right!
            foreach ($mix_ancestry as $arr_ancestor_element) {
                $this->prependGoogleKeyPathElement($path, $arr_ancestor_element);
            }
        } elseif ($mix_ancestry instanceof Entity) {
            // Recursive
            $this->walkGoogleKeyPathElement($path, $mix_ancestry);
        }

        return $path;
    }

    public function prependGoogleKeyPathElement($arr, Entity $obj_gds_entity)
    {
        $data = [
            'kind'  => $obj_gds_entity->getKind(),
            'id'    => $obj_gds_entity->getKeyId(),
            'name'  => $obj_gds_entity->getKeyName()
        ];
        return array_unshift($arr, $this->createGoogleKeyPathElement($data));
    }

    /**
     * Configure a Google Key Path Element object
     *
     * @param Key\PathElement $obj_path_element
     * @param array $arr_kpe
     */
    private function createGoogleKeyPathElement(array $arr_kpe)
    {
        $obj_path_element = new KeyPathElement();

        $obj_path_element->setKind($arr_kpe['kind']);
        isset($arr_kpe['id']) && $obj_path_element->setId($arr_kpe['id']);
        isset($arr_kpe['name']) && $obj_path_element->setName($arr_kpe['name']);

        return $obj_path_element;
    }

    /**
     * Populate a ProtoBuf Property Value from a GDS Entity field definition & value
     *
     * @todo compare with Google API implementation
     *
     * @param Value $obj_val
     * @param array $arr_field_def
     * @param $mix_value
     */
    private function configureGooglePropertyValue(array $arr_field_def, $mix_value)
    {
        $obj_val = new Value();
        // Indexed?
        $bol_index = TRUE;
        if(isset($arr_field_def['index']) && FALSE === $arr_field_def['index']) {
            $bol_index = FALSE;
        }
        $obj_val->setExcludeFromIndexes(!$bol_index);

        // null checks
        if(null === $mix_value) {
            return;
        }

        // Value
        switch ($arr_field_def['type']) {
            case Schema::PROPERTY_STRING:
                $obj_val->setStringValue((string)$mix_value);
                break;

            case Schema::PROPERTY_INTEGER:
                $obj_val->setIntegerValue((int)$mix_value);
                break;

            case Schema::PROPERTY_DATETIME:
                if($mix_value instanceof \DateTimeInterface) {
                    $obj_dtm = $mix_value;
                } else {
                    $obj_dtm = new \DateTimeImmutable($mix_value);
                }
                $timestamp = (new Timestamp())->setSeconds($obj_dtm->getTimestamp())->setNanos(1000 * $obj_dtm->format('u'));
                $obj_val->setTimestampValue($timestamp);
                break;

            case Schema::PROPERTY_DOUBLE:
            case Schema::PROPERTY_FLOAT:
                $obj_val->setDoubleValue(floatval($mix_value));
                break;

            case Schema::PROPERTY_BOOLEAN:
                $obj_val->setBooleanValue((bool)$mix_value);
                break;

            case Schema::PROPERTY_GEOPOINT:
                $geo = (new LatLng())->setLatitude($mix_value[0])->setLongitude($mix_value[1]);
                $obj_val->setGeoPointValue($geo);
                break;

            case Schema::PROPERTY_STRING_LIST:
                $obj_val->setExcludeFromIndexes(false); // Ensure we only index the values, not the list
                $vals = [];
                foreach ((array)$mix_value as $str) {
                    $vals[] = (new Value())->setStringValue($str)->setExcludeFromIndexes(!$bol_index);
                }
                $obj_val->setArrayValue($vals);
                break;

            default:
                throw new \RuntimeException('Unable to process field type: ' . $arr_field_def['type']);
        }
    }

    /**
     * Extract a datetime value
     *
     * @param object $obj_property
     * @return mixed
     */
    protected function extractDatetimeValue($obj_property)
    {
        // Attempt to retain microsecond precision
        return $obj_property->getTimestampValue()->toDateTime();
    }

    /**
     * Extract a String List value
     *
     * @param object $obj_property
     * @return mixed
     */
    protected function extractStringListValue($obj_property)
    {
        $arr_values = $obj_property->getArrayValue();
        if(count($arr_values) > 0) {
            $arr = [];
            foreach ($arr_values as $obj_val) {
                /** @var $obj_val Value */
                $arr[] = $obj_val->getStringValue();
            }
            return $arr;
        }
        return null;
    }

    /**
     * Extract a Geopoint value (lat/lon pair)
     *
     * @param \google\appengine\datastore\v4\Value $obj_property
     * @return Geopoint
     */
    protected function extractGeopointValue($obj_property)
    {
        $obj_gp_value = $obj_property->getGeoPointValue();
        return new Geopoint($obj_gp_value->getLatitude(), $obj_gp_value->getLongitude());
    }

    /**
     * Extract a single property value from a Property object
     *
     * Defer any varying data type extractions to child classes
     *
     * @param $int_type
     * @param object $obj_property
     * @return array
     * @throws \Exception
     */
    protected function extractPropertyValue($int_type, $obj_property)
    {
        switch ($int_type) {
            case Schema::PROPERTY_STRING:
                return $obj_property->getStringValue();

            case Schema::PROPERTY_INTEGER:
                return $obj_property->getIntegerValue();

            case Schema::PROPERTY_DATETIME:
                return $this->extractDatetimeValue($obj_property);

            case Schema::PROPERTY_DOUBLE:
            case Schema::PROPERTY_FLOAT:
                return $obj_property->getDoubleValue();

            case Schema::PROPERTY_BOOLEAN:
                return $obj_property->getBooleanValue();

            case Schema::PROPERTY_GEOPOINT:
                return $this->extractGeopointValue($obj_property);

            case Schema::PROPERTY_STRING_LIST:
                return $this->extractStringListValue($obj_property);

            case Schema::PROPERTY_DETECT:
                return $this->extractAutoDetectValue($obj_property);

        }
        throw new \Exception('Unsupported field type: ' . $int_type);
    }

    /**
     * Auto detect & extract a value
     *
     * @todo expand auto detect types
     *
     * @param Value $obj_property
     * @return mixed
     */
    protected function extractAutoDetectValue($obj_property)
    {
        switch ( $obj_property->getValueType() ) {
            case "string":
                return $obj_property->getStringValue();
                break;
            case "integer":
                return $obj_property->getIntegerValue();
                break;
            case "timestamp":
                return $this->extractDatetimeValue($obj_property);
                break;
            case "double":
                return $obj_property->getDoubleValue();
                break;
            case "boolean":
                return $obj_property->getBooleanValue();
                break;
            case "geo_point":
                return $this->extractGeopointValue($obj_property);
                break;
            case "array":
                return $this->extractStringListValue($obj_property);
                break;
            default:
                throw new \Exception('Unsupported field type: ' . $obj_property->getValueType());
                break;
        }
        return null;
    }
}