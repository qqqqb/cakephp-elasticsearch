<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         0.0.1
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ElasticSearch;

use ArrayObject;
use Cake\Core\App;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\RepositoryInterface;
use Cake\Datasource\RulesAwareTrait;
use Cake\ElasticSearch\Association\EmbedMany;
use Cake\ElasticSearch\Association\EmbedOne;
use Cake\ElasticSearch\Datasource\MappingSchema;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use Cake\ORM\RulesChecker;
use Cake\Utility\Inflector;
use Cake\Validation\ValidatorAwareTrait;
use Elastica\Document as ElasticaDocument;
use Elastica\Exception\NotFoundException;
use InvalidArgumentException;

/**
 * Base class for mapping types in indexes.
 *
 * A type in elastic search is approximately equivalent to a table or collection
 * in a relational datastore. While an index can have multiple types, this ODM maps
 * each type in an index maps to a class.
 */
class Type implements RepositoryInterface, EventListenerInterface, EventDispatcherInterface
{
    use EventDispatcherTrait;
    use RulesAwareTrait;
    use ValidatorAwareTrait;

    /**
     * Default validator name.
     *
     * @var string
     */
    const DEFAULT_VALIDATOR = 'default';

    /**
     * Validator provider name.
     *
     * @var string
     */
    const VALIDATOR_PROVIDER_NAME = 'collection';

    /**
     * The name of the event dispatched when a validator has been built.
     *
     * @var string
     */
    const BUILD_VALIDATOR_EVENT = 'Model.buildValidator';

    /**
     * Connection instance
     *
     * @var \Cake\ElasticSearch\Datasource\Connection
     */
    protected $_connection;

    /**
     * The name of the Elastic Search type this class represents
     *
     * @var string
     */
    protected $_name;

    /**
     * The name of the Elastic Search type this class represents
     *
     * @var string
     */
    protected $_alias;

    /**
     * BehaviorRegistry for this table
     *
     * @var \Cake\Elasticsearch\BehaviorRegistry
     */
    protected $_behaviors;

    /**
     * The name of the class that represent a single document for this type
     *
     * @var string
     */
    protected $_documentClass;

    /**
     * Collection of Embedded sub documents this type has.
     *
     * @var array
     */
    protected $embeds = [];

    /**
     * The mapping schema for this type.
     *
     * @var \Cake\ElasticSearch\Datasource\MappingSchema
     */
    protected $schema;

    /**
     * Constructor
     *
     * ### Options
     *
     * - `connection` The Elastica instance.
     * - `name` The name of the type. If this isn't set the name will be inferred from the class name.
     * - `eventManager` Used to inject a specific eventmanager.
     *
     * At the end of the constructor the `Model.initialize` event will be triggered.
     *
     * @param array $config The configuration options, see above.
     */
    public function __construct(array $config = [])
    {
        if (!empty($config['name'])) {
            $this->name($config['name']);
        }

        $this->alias($this->name());
        $this->name($config['connection']->configType());

        $config['_alias'] = $this->getAlias();

        if (!empty($config['connection'])) {
            $this->connection($config['connection']);
        }

        if (!empty($config['behaviors'])) {
            $behaviors = $config['behaviors'];
        }

        $eventManager = null;
        if (isset($config['eventManager'])) {
            $eventManager = $config['eventManager'];
        }
        $this->_eventManager = $eventManager ?: new EventManager();
        $this->_behaviors = new BehaviorRegistry();
        $this->_behaviors->setType($this);
        $this->initialize($config);
        $this->_eventManager->on($this);
        $this->dispatchEvent('Model.initialize');
    }

    /**
     * Initialize a table instance. Called after the constructor.
     *
     * You can use this method to define embedded documents,
     * define validation and do any other initialization logic you need.
     *
     * ```
     *  public function initialize(array $config)
     *  {
     *      $this->embedMany('Comments');
     *  }
     * ```
     *
     * @param array $config Configuration options passed to the constructor
     * @return void
     */
    public function initialize(array $config)
    {
    }

    /**
     * Mark a property in documents of this type as an embedded sub-document.
     *
     * Embedded documents are converted into instances of the named document type.
     * This allows you to attach entity level behavior to subsections of your documents.
     *
     * @param string $name The name of the property that contains the embedded document.
     * @param array $options The options for the embedded document.
     * @return void
     */
    public function embedOne($name, $options = [])
    {
        $this->embeds[] = new EmbedOne($name, $options);
    }

    /**
     * Mark a property in documents of this type as list of embedded sub-documents.
     *
     * Embedded documents are converted into instances of the named document type.
     * This allows you to attach entity level behavior to subsections of your documents.
     *
     * This method will make a list of embedded documents from the named property.
     *
     * @param string $name The name of the property that contains the embedded document.
     * @param array $options The options for the embedded document.
     * @return void
     */
    public function embedMany($name, $options = [])
    {
        $this->embeds[] = new EmbedMany($name, $options);
    }

    /**
     * Get the list of embedded documents this type has.
     *
     * @return array
     */
    public function embedded()
    {
        return $this->embeds;
    }

    /**
     * Get the event manager for this Table.
     *
     * @return \Cake\Event\EventManager
     */
    public function getEventManager()
    {
        return $this->_eventManager;
    }

    /**
     * Returns the connection instance or sets a new one
     *
     * @param \Cake\ElasticSearch\Datasource\Connection $conn the new connection instance
     * @return \Cake\ElasticSearch\Datasource\Connection
     */
    public function connection($conn = null)
    {
        if ($conn === null) {
            return $this->_connection;
        }

        return $this->_connection = $conn;
    }

    /**
     * Returns the type name name or sets a new one
     *
     * @param string $name the new type name
     * @return string
     */
    public function name($name = null)
    {
        if ($name !== null) {
            $this->_name = $name;
        }

        if ($this->_name === null) {
            $name = namespaceSplit(get_class($this));
            $name = substr(end($name), 0, -4);
            if (empty($name)) {
                $name = '*';
            }
            $this->_name = Inflector::underscore($name);
        }

        return $this->_name;
    }

    /**
     * Get the alias for this Type.
     *
     * This method is just an alias of name().
     *
     * @deprecated Use getAlias() and setAlias() instead
     * @param string $alias The new type name
     * @return string
     */
    public function alias($alias = null)
    {
      if ($alias !== null) {
          $this->_alias = $alias;
      }

      if ($this->_alias === null) {
          $alias = namespaceSplit(get_class($this));
          $alias = substr(end($alias), 0, -4);
          if (empty($alias)) {
              $alias = '*';
          }
          $this->_alias = Inflector::underscore($alias);
      }

      return $this->_alias;
    }

    /**
     * Sets the type alias.
     *
     * @param string $alias Table alias
     * @return $this
     */
    public function setAlias($alias)
    {
        $this->alias($alias);

        return $this;
    }

    /**
     * Returns the type alias.
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias();
    }

    /**
     * Get/set the type/table name for this type.
     *
     * @param string $table The 'table' name for this type.
     * @return string
     */
    public function table($table = null)
    {
        return $this->name($table);
    }

    /**
     * Creates a new Query for this repository and applies some defaults based on the
     * type of search that was selected.
     *
     * ### Model.beforeFind event
     *
     * Each find() will trigger a `Model.beforeFind` event for all attached
     * listeners. Any listener can set a valid result set using $query
     *
     * @param string $type the type of query to perform
     * @param array $options An array that will be passed to Query::applyOptions
     * @return \Cake\ElasticSearch\Query
     */
    public function find($type = 'all', $options = [])
    {
        $query = $this->query();

        return $this->callFinder($type, $query, $options);
    }

    /**
     * Returns the query as passed
     *
     * @param \Cake\ElasticSearch\Query $query An Elasticsearch query object
     * @param array $options An array of options to be used for query logic
     * @return \Cake\ElasticSearch\Query
     */
    public function findAll(Query $query, array $options = [])
    {
        return $query;
    }

    /**
     * Calls a finder method directly and applies it to the passed query,
     * if no query is passed a new one will be created and returned
     *
     * @param string $type name of the finder to be called
     * @param \Cake\ElasticSearch\Query $query The query object to apply the finder options to
     * @param array $options List of options to pass to the finder
     * @return \Cake\ElasticSearch\Query
     * @throws \BadMethodCallException
     */
    public function callFinder($type, Query $query, $options = [])
    {
        $query->applyOptions($options);
        $options = $query->getOptions();
        $finder = 'find' . ucfirst($type);

        if (method_exists($this, $finder)) {
            return $this->{$finder}($query, $options);
        }

        throw new \BadMethodCallException(
            sprintf('Unknown finder method "%s"', $type)
        );
    }

    /**
     * @{inheritdoc}
     *
     * Any key present in the options array will be translated as a GET argument
     * when getting the document by its id. This is often useful whe you need to
     * specify the parent or routing.
     *
     * This method will not trigger the Model.beforeFind callback as it does not use
     * queries for the search, but a faster key lookup to the search index.
     *
     * @param string $primaryKey The document's primary key
     * @param array $options An array of options
     * @throws \Elastica\Exception\NotFoundException if no document exist with such id
     * @return \Cake\ElasticSearch\Document A new Elasticsearch document entity
     */
    public function get($primaryKey, $options = [])
    {
        $type = $this->connection()->getIndex()->getType($this->name());
        $result = $type->getDocument($primaryKey, $options);
        $class = $this->entityClass();

        $options = [
            'markNew' => false,
            'markClean' => true,
            'useSetters' => false,
            'source' => $this->name(),
        ];
        $data = $result->getData();

        if (!isset($data['_alias']) || $data['_alias'] !== $this->alias()) {
            throw new NotFoundException('doc id '.$primaryKey.' not found');
        }

        $data['id'] = $result->getId();
        foreach ($this->embedded() as $embed) {
            $prop = $embed->property();
            if (isset($data[$prop])) {
                $data[$prop] = $embed->hydrate($data[$prop], $options);
            }
        }

        return new $class($data, $options);
    }

    /**
     * Creates a new Query instance for this repository
     *
     * @return \Cake\ElasticSearch\Query
     */
    public function query()
    {
        $query = new Query($this);
        return $query->queryMust(['_alias' => $this->alias()], True);
    }

    /**
     * Get a marshaller for this Type instance.
     *
     * @return \Cake\ElasticSearch\Marshaller
     */
    public function marshaller()
    {
        return new Marshaller($this);
    }

    /**
     * Update all matching records.
     *
     * Sets the $fields to the provided values based on $conditions.
     * This method will *not* trigger beforeSave/afterSave events. If you need those
     * first load a collection of records and update them.
     *
     * @param array $fields A hash of field => new value.
     * @param array $conditions An array of conditions, similar to those used with find()
     * @return void
     */
    public function updateAll($fields, $conditions)
    {
        throw new \RuntimeException('Not implemented yet');
    }

    /**
     * Delete all matching records.
     *
     * Deletes all records matching the provided conditions.
     *
     * This method will *not* trigger beforeDelete/afterDelete events. If you
     * need those first load a collection of records and delete them.
     *
     * @param array $conditions An array of conditions, similar to those used with find()
     * @return bool Success Returns true if one or more rows are effected.
     * @see RepositoryInterface::delete()
     */
    public function deleteAll($conditions)
    {
        $query = $this->query();
        $query->where($conditions);
        $type = $this->connection()->getIndex()->getType($this->name());
        $response = $type->deleteByQuery($query->compileQuery());

        return $response->isOk();
    }

    /**
     * Returns true if there is any record in this repository matching the specified
     * conditions.
     *
     * @param array $conditions list of conditions to pass to the query
     * @return bool
     */
    public function exists($conditions)
    {
        $query = $this->query();
        if (count($conditions) && isset($conditions['id'])) {
            $query->where(function ($builder) use ($conditions) {
                return $builder->ids((array)$conditions['id']);
            });
        } else {
            $query->where($conditions);
        }

        return $query->count() > 0;
    }

    /**
     * Persists a list of entities based on the fields that are marked as dirty and
     * returns the same entity after a successful save or false in case
     * of any error.
     * Triggers the `Model.beforeSave` and `Model.afterSave` events.
     * ## Options
     * - `checkRules` Defaults to true. Check deletion rules before deleting the record.
     *
     * @param array $entities An array of entities
     * @param array $options An array of options to be used for the event
     * @return bool
     */
    public function saveMany($entities, $options = [])
    {
        $options += ['checkRules' => true];
        $options = new ArrayObject($options);

        $documents = [];

        foreach ($entities as $key => $entity) {
            if (!$entity instanceof EntityInterface) {
                throw new RuntimeException(sprintf(
                    'Invalid items in the list. Found `%s` but expected `%s`',
                    is_object($entity) ? get_class($entity) : gettype($entity),
                    EntityInterface::class
                ));
            }

            $event = $this->dispatchEvent('Model.beforeSave', [
                'entity' => $entity,
                'options' => $options
            ]);

            if ($event->isStopped() || $entity->errors()) {
                return false;
            }

            $mode = $entity->isNew() ? RulesChecker::CREATE : RulesChecker::UPDATE;
            if ($options['checkRules'] && !$this->checkRules($entity, $mode, $options)) {
                return false;
            }

            $id = $entity->id ?: null;

            $data = $entity->toArray();
            unset($data['id'], $data['_version']);

            $doc = new ElasticaDocument($id, $data);
            $doc->setAutoPopulate(true);

            $documents[$key] = $doc;
        }

        $type = $this->connection()->getIndex()->getType($this->name());
        $type->addDocuments($documents);

        foreach ($documents as $key => $document) {
            $entities[$key]->id = $doc->getId();
            $entities[$key]->_version = $doc->getVersion();
            $entities[$key]->isNew(false);
            $entities[$key]->source($this->name());
            $entities[$key]->clean();

            $this->dispatchEvent('Model.afterSave', [
                'entity' => $entities[$key],
                'options' => $options
            ]);
        }

        return true;
    }

    /**
     * Persists an entity based on the fields that are marked as dirty and
     * returns the same entity after a successful save or false in case
     * of any error.
     *
     * Triggers the `Model.beforeSave` and `Model.afterSave` events.
     *
     * ## Options
     *
     * - `checkRules` Defaults to true. Check deletion rules before deleting the record.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to be saved
     * @param array $options An array of options to be used for the event
     * @return \Cake\Datasource\EntityInterface|bool
     */
    public function save(EntityInterface $entity, $options = [])
    {
        $options += ['checkRules' => true];
        $options = new ArrayObject($options);
        $event = $this->dispatchEvent('Model.beforeSave', [
            'entity' => $entity,
            'options' => $options
        ]);

        if ($event->isStopped()) {
            return $event->result;
        }

        if ($entity->errors()) {
            return false;
        }

        $mode = $entity->isNew() ? RulesChecker::CREATE : RulesChecker::UPDATE;

        if ($options['checkRules'] && !$this->checkRules($entity, $mode, $options)) {
            return false;
        }

        $type = $this->connection()->getIndex()->getType($this->name());
        $id = $entity->id ?: null;

        $data = $entity->toArray();
        unset($data['id'], $data['_version']);

        $doc = new ElasticaDocument($id, $data);
        $doc->setRefresh(True);
        $doc->setAutoPopulate(true);

        $type->addDocument($doc);

        $entity->id = $doc->getId();
        $entity->_version = $doc->getVersion();
        $entity->isNew(false);
        $entity->source($this->name());
        $entity->clean();

        $this->dispatchEvent('Model.afterSave', [
            'entity' => $entity,
            'options' => $options
        ]);

        return $entity;
    }

    /**
     * Delete a single entity.
     *
     * Deletes an entity and possibly related associations from the database
     * based on the 'dependent' option used when defining the association.
     *
     * Triggers the `Model.beforeDelete` and `Model.afterDelete` events.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to remove.
     * @param array $options The options for the delete.
     * @return bool success
     */
    public function delete(EntityInterface $entity, $options = [])
    {
        if (!$entity->has('id')) {
            $msg = 'Deleting requires an "id" value.';
            throw new InvalidArgumentException($msg);
        }
        $options += ['checkRules' => true];
        $options = new ArrayObject($options);
        $event = $this->dispatchEvent('Model.beforeDelete', [
            'entity' => $entity,
            'options' => $options
        ]);

        if ($event->isStopped()) {
            return $event->result;
        }

        if (!$this->checkRules($entity, RulesChecker::DELETE, $options)) {
            return false;
        }

        $data = $entity->toArray();
        unset($data['id']);

        $doc = new ElasticaDocument($entity->id, $data);
        $doc->setRefresh(True);
        
        $type = $this->connection()->getIndex()->getType($this->name());
        $result = $type->deleteDocument($doc);

        $this->dispatchEvent('Model.afterDelete', [
            'entity' => $entity,
            'options' => $options
        ]);

        return $result->isOk();
    }

    /**
     * Create a new entity + associated entities from an array.
     *
     * This is most useful when hydrating request data back into entities.
     * For example, in your controller code:
     *
     * ```
     * $article = $this->Articles->newEntity($this->request->data());
     * ```
     *
     * The hydrated entity will correctly do an insert/update based
     * on the primary key data existing in the database when the entity
     * is saved. Until the entity is saved, it will be a detached record.
     *
     * @param array|null $data The data to build an entity with.
     * @param array $options A list of options for the object hydration.
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEntity($data = null, array $options = [])
    {
        if ($data === null) {
            $class = $this->entityClass();

            return new $class([], ['source' => $this->name()]);
        }

        return $this->marshaller()->one($data, $options);
    }

    /**
     * Create a list of entities + associated entities from an array.
     *
     * This is most useful when hydrating request data back into entities.
     * For example, in your controller code:
     *
     * ```
     * $articles = $this->Articles->newEntities($this->request->data());
     * ```
     *
     * The hydrated entities can then be iterated and saved.
     *
     * @param array $data The data to build an entity with.
     * @param array $options A list of options for the objects hydration.
     * @return array An array of hydrated records.
     */
    public function newEntities(array $data, array $options = [])
    {
        return $this->marshaller()->many($data, $options);
    }

    /**
     * Returns the class used to hydrate rows for this table or sets
     * a new one
     *
     * @param string $name the name of the class to use
     * @throws \RuntimeException when the entity class cannot be found
     * @return string
     */
    public function entityClass($name = null)
    {
        if ($name === null && !$this->_documentClass) {
            $default = '\Cake\ElasticSearch\Document';
            $self = get_called_class();
            $parts = explode('\\', $self);

            if ($self === __CLASS__ || count($parts) < 3) {
                return $this->_documentClass = $default;
            }

            $alias = Inflector::singularize(substr(array_pop($parts), 0, -4));
            $name = implode('\\', array_slice($parts, 0, -1)) . '\Document\\' . $alias;
            if (!class_exists($name)) {
                return $this->_documentClass = $default;
            }
        }

        if ($name !== null) {
            $class = App::classname($name, 'Model/Document');
            $this->_documentClass = $class;
        }

        if (!$this->_documentClass) {
            throw new \RuntimeException(sprintf('Missing document class "%s"', $class));
        }

        return $this->_documentClass;
    }

    /**
     * Merges the passed `$data` into `$entity` respecting the accessible
     * fields configured on the entity. Returns the same entity after being
     * altered.
     *
     * This is most useful when editing an existing entity using request data:
     *
     * ```
     * $article = $this->Articles->patchEntity($article, $this->request->data());
     * ```
     *
     * @param \Cake\Datasource\EntityInterface $entity the entity that will get the
     * data merged in
     * @param array $data key value list of fields to be merged into the entity
     * @param array $options A list of options for the object hydration.
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchEntity(EntityInterface $entity, array $data, array $options = [])
    {
        $marshaller = $this->marshaller();
        $data['_alias'] = $this->alias();
        return $marshaller->merge($entity, $data, $options);
    }

    /**
     * Merges each of the elements passed in `$data` into the entities
     * found in `$entities` respecting the accessible fields configured on the entities.
     * Merging is done by matching the primary key in each of the elements in `$data`
     * and `$entities`.
     *
     * This is most useful when editing a list of existing entities using request data:
     *
     * ```
     * $article = $this->Articles->patchEntities($articles, $this->request->data());
     * ```
     *
     * @param array|\Traversable $entities the entities that will get the
     * data merged in
     * @param array $data list of arrays to be merged into the entities
     * @param array $options A list of options for the objects hydration.
     * @return array
     */
    public function patchEntities($entities, array $data, array $options = [])
    {
        $marshaller = $this->marshaller();
        $data['alias'] = $this->alias();
        return $marshaller->mergeMany($entities, $data, $options);
    }

    /**
     * Get the mapping data from the index type.
     *
     * This will fetch the schema from ElasticSearch the first
     * time this method is called.
     *
     *
     * @return array
     */
    public function schema()
    {
        if ($this->schema !== null) {
            return $this->schema;
        }
        $name = $this->name();
        $type = $this->connection()->getIndex()->getType($name);
        $this->schema = new MappingSchema($name, $type->getMapping());

        return $this->schema;
    }

    /**
     * Check whether or not a field exists in the mapping.
     *
     * @param string $field The field to check.
     * @return bool
     */
    public function hasField($field)
    {
        return $this->schema()->field($field) !== null;
    }

    /**
     * Get the callbacks this Type is interested in.
     *
     * By implementing the conventional methods a Type class is assumed
     * to be interested in the related event.
     *
     * Override this method if you need to add non-conventional event listeners.
     * Or if you want you table to listen to non-standard events.
     *
     * The conventional method map is:
     *
     * - Model.beforeMarshal => beforeMarshal
     * - Model.beforeFind => beforeFind
     * - Model.beforeSave => beforeSave
     * - Model.afterSave => afterSave
     * - Model.beforeDelete => beforeDelete
     * - Model.afterDelete => afterDelete
     * - Model.beforeRules => beforeRules
     * - Model.afterRules => afterRules
     *
     * @return array
     */
    public function implementedEvents()
    {
        $eventMap = [
            'Model.beforeMarshal' => 'beforeMarshal',
            'Model.beforeFind' => 'beforeFind',
            'Model.beforeSave' => 'beforeSave',
            'Model.afterSave' => 'afterSave',
            'Model.beforeDelete' => 'beforeDelete',
            'Model.afterDelete' => 'afterDelete',
            'Model.beforeRules' => 'beforeRules',
            'Model.afterRules' => 'afterRules',
        ];
        $events = [];

        foreach ($eventMap as $event => $method) {
            if (!method_exists($this, $method)) {
                continue;
            }
            $events[$event] = $method;
        }

        return $events;
    }

    /**
     * Add a behavior.
     *
     * Adds a behavior to this table's behavior collection. Behaviors
     * provide an easy way to create horizontally re-usable features
     * that can provide trait like functionality, and allow for events
     * to be listened to.
     *
     * Example:
     *
     * Load a behavior, with some settings.
     *
     * ```
     * $this->addBehavior('Tree', ['parent' => 'parentId']);
     * ```
     *
     * Behaviors are generally loaded during Table::initialize().
     *
     * @param string $name The name of the behavior. Can be a short class reference.
     * @param array $options The options for the behavior to use.
     * @return $this
     * @throws \RuntimeException If a behavior is being reloaded.
     * @see \Cake\ORM\Behavior
     */
    public function addBehavior($name, array $options = [])
    {
        $this->_behaviors->load($name, $options);

        return $this;
    }

    /**
     * Removes a behavior from this table's behavior registry.
     *
     * Example:
     *
     * Remove a behavior from this table.
     *
     * ```
     * $this->removeBehavior('Tree');
     * ```
     *
     * @param string $name The alias that the behavior was added with.
     * @return $this
     * @see \Cake\ORM\Behavior
     */
    public function removeBehavior($name)
    {
        $this->_behaviors->unload($name);

        return $this;
    }

    /**
     * Returns the behavior registry for this table.
     *
     * @return \Cake\ORM\BehaviorRegistry The BehaviorRegistry instance.
     */
    public function behaviors()
    {
        return $this->_behaviors;
    }

    /**
     * Check if a behavior with the given alias has been loaded.
     *
     * @param string $name The behavior alias to check.
     * @return bool Whether or not the behavior exists.
     */
    public function hasBehavior($name)
    {
        return $this->_behaviors->has($name);
    }

    /**
     * The default connection name to inject when creating an instance.
     *
     * @return string
     */
    public static function defaultConnectionName()
    {
        return 'elastic';
    }
}
