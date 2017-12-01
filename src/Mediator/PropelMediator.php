<?php

namespace UWDOEM\REST\Backend\Mediator;

use Propel\Runtime\Map\TableMap;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Class PropelMediator
 * @package UWDOEM\REST\Backend\Mediator
 *
 * This class implements the MediatorInterface. See that interface for more detailed descriptions of
 * public class methods.
 */
class PropelMediator implements MediatorInterface
{
    /** @var string $href The base HREF for this API, eg: https://my.api.com/v1/ */
    protected $href;

    /** @var string $classMap A map from resource type names to class names, eg: ['surveys' => '\Schema\Survey',...] */
    protected $classMap;

    /** @var callable[] $extraAttributeProviders A map from resource type names to callables */
    protected $extraAttributeProviders;

    /** @var string[] $errors This instance will add error descriptions to this list as it encounters them */
    protected $errors = [];

    /** @var string[] $condMap Map from MediatorInterface filter conditions to Propel filter conditions */
    protected static $condMap = [
        MediatorInterface::COND_GT => Criteria::GREATER_THAN,
        MediatorInterface::COND_LT => Criteria::LESS_THAN,
        MediatorInterface::COND_EQUAL => Criteria::EQUAL,
        MediatorInterface::COND_GTE => Criteria::GREATER_EQUAL,
        MediatorInterface::COND_LTE => Criteria::LESS_EQUAL,
        MediatorInterface::COND_NOT_EQUAL => Criteria::NOT_EQUAL,
        MediatorInterface::COND_LIKE => Criteria::LIKE,
        MediatorInterface::COND_NULL => Criteria::ISNULL,
        MediatorInterface::COND_NOT_NULL => Criteria::ISNOTNULL,
    ];

    /**
     * PropelMediator constructor.
     * @param string $baseHref
     * @param array  $classMap
     * @param array  $extraAttributeProviders
     */
    public function __construct($baseHref, array $classMap, array $extraAttributeProviders = [])
    {
        $this->href = $baseHref;
        $this->classMap = $classMap;
        $this->extraAttributeProviders = $extraAttributeProviders;
    }

    /**
     * @param mixed $resource
     * @return boolean|mixed
     */
    public function save($resource)
    {
        if (method_exists($resource, 'validate') === true && $resource->validate() === false) {
            foreach ($resource->getValidationFailures() as $failure) {
                $this->errors[] = "Property ".$failure->getPropertyPath().": ".$failure->getMessage()."\n";
            }

            return false;
        }

        try {
            $resource->save();
        } catch (\Exception $e) {
            $this->errors[] = 'Our database encountered an error fulfilling your request.';
            $this->errors[] = $e->getMessage();

            return false;
        }

        return $resource;
    }

    /**
     * @param string $resourceType
     * @return mixed
     */
    public function create($resourceType)
    {
        $selectedClass = $this->classMap[$resourceType];

        $resource = new $selectedClass();
        return $resource;
    }

    /**
     * @param mixed $resource
     * @param array $attributes
     * @return mixed
     */
    public function setAttributes($resource, array $attributes)
    {
        $resource->fromArray($attributes, TableMap::TYPE_FIELDNAME);
        return $resource;
    }

    /**
     * @param mixed $resource
     * @return mixed
     */
    public function getAttributes($resource)
    {

        $attributes = $resource->toArray(TableMap::TYPE_FIELDNAME);

        $resourceType = array_search(get_class($resource), $this->classMap);

        $tableMapClass = $resource::TABLE_MAP;
        $columns = $tableMapClass::getTableMap()->getColumns();

        foreach ($columns as $key => $column) {
            if ($column->isForeignKey() === true) {
                $foreignKeyName = $column->getName();
                $foreignKeyValue = $attributes[$foreignKeyName];
                $foreignResourceType = array_search(
                    trim($column->getRelatedTable()->getClassName(), '\\'),
                    $this->classMap
                );
                $foreignReferenceName = substr($foreignKeyName, 0, -3);

                if (array_key_exists($foreignKeyName, $attributes) === true && $attributes[$foreignKeyName] !== null) {
                    $attributes[$foreignReferenceName] = "{$this->href}/$foreignResourceType/$foreignKeyValue";
                } else {
                    $attributes[$foreignReferenceName] = null;
                }
            } elseif ($column->getType() === 'TIMESTAMP') {
                $attributes[$column->getName()] = strtotime($attributes[$column->getName()]);
            }
        }

        $attributes["href"] = "{$this->href}/$resourceType/{$attributes['id']}/";

        if (array_key_exists($resourceType, $this->extraAttributeProviders) === true) {
            $attributes = $this->extraAttributeProviders[$resourceType]($attributes);
        }

        return $attributes;
    }

    /**
     * @param string $resourceType
     * @param mixed  $key
     * @return boolean
     */
    public function retrieve($resourceType, $key)
    {
        $queryClass = $this->classMap[$resourceType];
        $queryClass .= "Query";
        $query = $queryClass::create()->findOneById($key);
        return ($query !== null ? $query : false);
    }

    /**
     * @param string $resourceType
     * @return mixed
     */
    public function retrieveList($resourceType)
    {
        $queryClass = $this->classMap[$resourceType];
        $queryClass .= "Query";
        $query = $queryClass::create();
        return $query;
    }

    /**
     * @param mixed   $collection
     * @param integer $limit
     * @return mixed
     */
    public function limit($collection, $limit)
    {
        $limit = max(1, $limit);

        return $collection->limit($limit);
    }

    /**
     * @param mixed $collection
     * @return mixed
     */
    public function collectionToIterable($collection)
    {
        return $collection->find();
    }

    /**
     * @param mixed   $collection
     * @param integer $offset
     * @return mixed
     */
    public function offset($collection, $offset)
    {
        return $collection->offset($offset);
    }

    /**
     * @param mixed      $collection
     * @param string     $attribute
     * @param string     $operator
     * @param mixed|null $value
     * @return mixed
     */
    public function filter($collection, $attribute, $operator, $value = null)
    {
        $attribute = $collection->getTableMap()->getColumn($attribute)->getPhpName();
        $propelOperator = static::$condMap[$operator];
        return $collection->filterBy($attribute, $value, $propelOperator);
    }

    /**
     * @param mixed $resource
     * @return mixed
     */
    public function delete($resource)
    {
        $resource->delete();

        return $resource->isDeleted();
    }

    /**
     * @param string $resourceType
     * @return boolean
     */
    public function resourceTypeExists($resourceType)
    {
        return array_key_exists($resourceType, $this->classMap);
    }

    /**
     * @return array
     */
    public function error()
    {
        return $this->errors;
    }
}
