<?php

namespace Ponticlaro\Bebop\Mvc;

use Ponticlaro\Bebop\Common\Collection;
use Ponticlaro\Bebop\Common\ContextManager;
use Ponticlaro\Bebop\Common\FeatureManager;
use Ponticlaro\Bebop\Db\Query;
use Ponticlaro\Bebop\Mvc\Helpers\ModelFactory;

class Model {

  /**
   * Flag for model initialization
   * 
   * @var string
   */
  protected static $__init = false;

  /**
   * Model type
   * 
   * @var string
   */
  protected static $__type;

  /**
   * Function to be executed on instantiation
   * 
   * @var string
   */
  protected static $init_mods;

  /**
   * List with model context based modifications
   * 
   * @var object Ponticlaro\Bebop\Common\Collection;
   */
  protected static $context_mods;

  /**
   * List with model loadables sets
   * 
   * @var object Ponticlaro\Bebop\Common\Collection;
   */
  protected static $loadables_sets;

  /**
   * List with model loadables
   * 
   * @var object Ponticlaro\Bebop\Common\Collection;
   */
  protected static $loadables;

  /**
   * Current query instance
   * 
   * @var Ponticlaro\Bebop\Db\Query;
   */
  protected static $query;

  /**
   * List of already loaded loadables sets and loadables
   * 
   * @var array
   */
  private $__loaded = [];

  /**
   * Instantiates new model by inheriting all the $post properties
   * 
   * @param WP_Post $post
   */
  final public function __construct($post = null)
  { 
    // Handle model configuration
    if (!static::$__init && is_string($post)) {
      static::__init($post);
    }

    // Handle model instance
    elseif ($post instanceof \WP_Post) {
      
      // making sure model is configured before continuing
      if (!static::$__init)
        static::__init($post->post_type);

      // Collect all $post properties
      foreach ((array) $post as $key => $value) {
        $this->{$key} = $value;
      }

      // Add permalink properties to relevant types
      if (!in_array($post->post_type, array('attachment', 'revision', 'nav_menu')))
        $this->permalink = get_permalink($this->ID);

      static::__applyInitMods($this);
      static::__applyContextMods($this);
    }
  }

  /**
   * Creates an instance of the currently called class
   * 
   * @return Ponticlaro\Bebop\Mvc\Model
   */
  public function applyTo(\WP_Post $post)
  {
    return new static($post);
  }

  /**
   * Returns post-type for this model
   *
   * @return  string Post-type name
   */
  public static function getType()
  {
    return static::$__type;
  }

  /**
   * Returns post meta
   * 
   * @param  string  $key    Meta key
   * @param  boolean $single True if meta value is single, false otherwise
   * @return mixed           
   */
  public function getMeta($key, $single = false)
  {
    return get_post_meta($this->ID, $key, $single);
  }

  /**
   * Sets the post type name
   * 
   * @param string $type
   */
  public static function setType($type)
  {
    if (is_string($type))
      static::$__type = $type;

    return $this;
  }

  /**
   * Sets a function to be executed for every single item,
   * right after being fetched from the database
   * 
   * @param  callable $fn Function to be executed
   */
  public function onInit($fn)
  {
    if (is_callable($fn))
      static::$init_mods = $fn;

    return $this;
  }

  /**
   * Adds a function to execute when the target context key is active
   * 
   * @param string $context_keys Target context keys
   * @param string $fn           Function to execute
   */
  public function onContext($context_keys, $fn)
  {   
    if (is_callable($fn)) {

      if (is_string($context_keys)) {
         
        static::$context_mods->set($context_keys, $fn);
      }

      elseif (is_array($context_keys)) {
        foreach ($context_keys as $context_key) {
           
          static::$context_mods->set($context_key, $fn);
        }
      }
    }

    return $this;
  }

  /**
   * Adds a set of functions to load optional content or apply optional modifications 
   * 
   * @param string   $id Loadable set ID
   * @param callable $fn List of loadables to load
   */
  public function addLoadableSet($id, array $loadables)
  {
    static::$loadables_sets->set($id, $loadables);

    return $this;
  }

  /**
   * Adds a single function to load optional content or apply optional modifications 
   * 
   * @param string   $id Loadable ID
   * @param callable $fn Loadable function
   */
  public function addLoadable($id, $fn)
  {
    static::$loadables->set($id, $fn);

    return $this;
  }

  /**
   * Executes loadables sets or loadables by ID
   * 
   * @param array $ids List of loadables sets IDs or loadables IDs
   */
  public function load(array $ids = array())
  {
    // Handle loadables sets
    if (static::$loadables_sets) {

      foreach ($ids as $loadable_set_key => $loadable_set_id) {
        if (static::$loadables_sets->hasKey($loadable_set_id) && !in_array($loadable_set_id, $this->__loaded)) {

          // Making sure we do not load a loadable 
          // with the same name as this loadable set
          unset($ids[$loadable_set_key]);

          // Loop through all loadables ids on this set
          foreach (static::$loadables_sets->get($loadable_set_id) as $loadable_id) {
              
            // Add loadable ID, if not already present
            if ($loadable_id != $loadable_set_id && !in_array($loadable_id, $ids))
              $ids[] = $loadable_id;
          }

          // Mark loadable set as loaded
          $this->__loaded[] = $loadable_set_id;
        }
      }
    }

    // Handle loadables
    if (static::$loadables) {

      foreach ($ids as $id) {
        if (static::$loadables->hasKey($id) && !in_array($id, $this->__loaded)) {

          $lac_feature = FeatureManager::getInstance()->get('mvc/model/loadables_auto_context');

          if ($lac_feature && $lac_feature->isEnabled())
            ContextManager::getInstance()->overrideCurrent('loadable/'. static::$getType() .'/'. $id);

          call_user_func_array(static::$loadables->get($id), array($this));

          if ($lac_feature && $lac_feature->isEnabled())
            ContextManager::getInstance()->restoreCurrent();

          // Mark loadable as loaded
          $this->__loaded[] = $id;
        }
      }
    }

    return $this;
  }

  /**
   * Executes loadables sets and loadables on target contexts
   * 
   * @param  string $context_keys  Target context keys
   * @param  array  $loadables_ids List of loadables sets IDs or loadables IDs
   * @return void 
   */
  public function loadOnContext($context_keys, array $loadables)
  {
    static::onContext($context_keys, function($model) use($loadables) {
      $model->load($loadables);
    });

    return $this;
  }

  /**
   * Returns entries by ID
   * 
   * @param  mixed $ids        Single ID or array of IDs
   * @param  bool  $keep_order True if posts order should match the order of $ids, false otherwise
   * @return mixed             Single object or array of objects
   */
  public function find($ids = null, array $options = [])
  {
    // Merge user options with default ones
    $options = array_merge([
      'keep_order' => true
    ], $options);

    // Make sure we have a clean query object to be used
    static::__resetQuery();

    // Setting placeholder for data to return
    $data = null;

    // Get current context global $post
    if (is_null($ids)) {
        
      if (ContextManager::getInstance()->is('single')) {
          
        global $post;

        return new static($post);
      }
    }

    else {

      // Set post type argument
      static::$query->postType(static::getType());

      // Change status to inherit on attachments
      if (static::getType() == 'attachment')
        static::$query->status('inherit');

      // Get results
      $data = static::$query->find($ids, $options['keep_order']);

      if ($data) {
          
        if (is_object($data)) {
          
          $data = new static($data);
        }

        elseif (is_array($data)) {
          foreach ($data as $key => $post) {
              
            $data[$key] = new static($post);
          }
        }
      }
    }

    return $data;
  }

  /**
   * Returns all posts that match the defined query
   * 
   * @return array
   */
  public function findAll(array $args = array())
  {   
    // Make sure we have a query object to be used
    static::__enableQueryMode();

    // Add post type as final argument
    static::$query->postType(static::getType());

    // Change status to inherit on attachments
    if (static::getType() == 'attachment')
      static::$query->status('inherit');

    // Get query results
    $items = static::$query->findAll($args);

    // Apply model modifications
    if ($items) {
      foreach ($items as $key => $item) {
        $items[$key] = new static($item);
      }
    }

    return $items;
  }

  /**
   * Returns current query object
   * 
   * @return Ponticlaro\Bebop\Db\Query
   */
  public static function query()
  {
    // Make sure we have a query object to be used
    if(is_null(static::$query))
      static::__enableQueryMode();

    return static::$query;
  }

  /**
   * Calls query methods while on static context
   * 
   * @param  string $name Method name
   * @param  array  $args Method args
   * @return object       Called class instance
   */
  public static function __callStatic($name, $args)
  {
    static::__enableQueryMode();

    call_user_func_array(array(static::$query, $name), $args);

    return $this;
  }

  /**
   * Calls query methods while on intance context
   * 
   * @param  string $name Method name
   * @param  array  $args Method args
   * @return object       Called class instance
   */
  public function __call($name, $args)
  {
    static::__enableQueryMode();

    call_user_func_array(array(static::$query, $name), $args);

    return $this;
  }

  /**
   * Initializes data model
   * 
   * @param  string $type Data model type
   * @return void
   */
  private static function __init($type)
  {
    static::$__type         = $type;
    static::$context_mods   = (new Collection())->disableDottedNotation();
    static::$loadables_sets = (new Collection())->disableDottedNotation();
    static::$loadables      = (new Collection())->disableDottedNotation();
    static::$__init         = true;
  }

  /**
   * Calls the function that applies initialization modifications
   * 
   * @param  object $item Object to be modified
   * @return void
   */
  private static function __applyInitMods(&$item)
  {
    if (!is_null(static::$init_mods))
      call_user_func_array(static::$init_mods, array($item));
  }

  /**
   * 
   * Executes any function that exists for the current context
   * 
   * @param class $item WP_Post instance converted into an instance of the current class
   */
  private function __applyContextMods(&$item)
  {
    // Get current environment
    $context     = ContextManager::getInstance();
    $context_key = $context->getCurrent();

    // Execute current environment function
    if (!is_null(static::$context_mods)) {

      // Exact match for the current environment
      if (static::$context_mods->hasKey($context_key)) {
          
        call_user_func_array(static::$context_mods->get($context_key), array($item));
      } 

      // Check for partial matches
      else {

        foreach (static::$context_mods->getAll() as $key => $fn) {
        
          if ($context->is($key))
            call_user_func_array($fn, array($item));
        }
      }
    }
  }

  /**
   * Enables query mode and creates a new query
   * 
   * @return void
   */
  private static function __enableQueryMode()
  {
    if (is_null(static::$query) || static::$query->wasExecuted())
      static::__resetQuery();
  }

  /**
   * Destroys current query and creates a new one
   * 
   * @return void
   */
  private static function __resetQuery()
  {
    static::$query = new Query;
  }
}