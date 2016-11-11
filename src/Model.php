<?php

namespace Ponticlaro\Bebop\Mvc;

use Ponticlaro\Bebop\Common\Collection;
use Ponticlaro\Bebop\Common\ContextManager;
use Ponticlaro\Bebop\Common\FeatureManager;
use Ponticlaro\Bebop\Db\Query;
use Ponticlaro\Bebop\Mvc\Helpers\ModelFactory;
use Ponticlaro\Bebop\Mvc\Traits\Attachment as AttachmentTrait;

class Model {

  /**
   * Add methods necessary for the attachment post-type
   * 
   */
  use AttachmentTrait;

  /**
   * Holds data models with configuration for each post-type
   * 
   * @var array
   */
  private static $__config_models = [];

  /**
   * Configuration container for configuration specific data model instances
   * 
   * @var string
   */
  private $__config;

  /**
   * Model type
   * 
   * @var string
   */
  private $__type;

  /**
   * List of already loaded loadables sets and loadables
   * 
   * @var array
   */
  private $__loaded = [];

  /**
   * Instantiates new model by inheriting all the $post properties
   * 
   * @param Mixed $input WP_Post object or post-type string
   */
  final public function __construct($input)
  { 
    // Handle data model configuration
    if (is_string($input)) {

      // If we already have a configuration data model
      if (isset(static::$__config_models[$input])) {

        // Get configuration from existing configuration data model
        $this->__type   = static::$__config_models[$input]->__type;
        $this->__config = static::$__config_models[$input]->__config;

        // Assign this object as the new configuration data model
        static::$__config_models[$input] = $this;
      }

      // Initialize configuration data model
      else {

        $this->__init($input);
      }
    }

    // Handle model instance
    elseif ($input instanceof \WP_Post) {

      // We MUST set __type
      $this->setType($input->post_type);

      // Collect all $post properties
      foreach ((array) $input as $key => $value) {
        $this->{$key} = $value;
      }

      // Add permalink properties to relevant types
      if (!in_array($input->post_type, array('attachment', 'revision', 'nav_menu')))
        $this->permalink = get_permalink($this->ID);

      // Set default attachment post-type modifications on the Bebop HTTP API
      if ($input->post_type == 'attachment') {
        
        $this->onContext('api', function($model) {
          $model->cacheAllImageSizes();
        });  
      }

      // If not an attachment, remove sizes property
      else {

        unset($this->sizes);
      }

      $this->__applyInitMods($this);
      $this->__applyContextMods($this);
    }
  }

  /**
   * Creates an instance of the currently called class
   * 
   * @return Ponticlaro\Bebop\Mvc\Model
   */
  public function applyTo(\WP_Post $post)
  {
    return new self($post);
  }

  /**
   * Sets the post type name
   * 
   * @param string $type
   */
  public function setType($type)
  {
    if (is_string($type))
      $this->__type = $type;

    return $this;
  }

  /**
   * Returns post-type for this model
   *
   * @return  string Post-type name
   */
  public function getType()
  {
    return $this->__type;
  }

  /**
   * Sets the value for the target data model configuration key
   * 
   * @param string $key   Configuration key
   * @param mixed  $value Configuration value
   */
  public function setConfig($key, $value)
  {
    $this->__getConfigModel()->__config->set($key, $value);

    return $this;
  }

  /**
   * Returns the value for the target data model configuration key
   * 
   * @param  string $key Configuration key
   * @return mixed       Configuration value
   */
  public function getConfig($key)
  {
    return $this->__getConfigModel()->__config->get($key);
  }

  /**
   * Returns config data model full configuration data
   * 
   * @return array Data model full configuration data
   */
  public function getAllConfig()
  {
    var_dump(static::$__config_models);

    return $this->__getConfigModel()->__config->getAll();
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
   * Sets a function to be executed for every single item,
   * right after being fetched from the database
   * 
   * @param  callable $fn Function to be executed
   */
  public function onInit($fn)
  {
    // Get configuration data model
    $model = $this->__getConfigModel();

    if (is_callable($fn))
      $model->__config->set('init_mods', $fn);

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
    // Get configuration data model
    $model = $this->__getConfigModel();

    if (is_callable($fn)) {

      if (is_string($context_keys)) {
         
        $model->__config->set("context_mods.$context_keys", $fn);
      }

      elseif (is_array($context_keys)) {
        foreach ($context_keys as $context_key) {
           
          $model->__config->set("context_mods.$context_key", $fn);
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
    $this->__getConfigModel()->__config->set("loadables_sets.$id", $loadables);

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
    $this->__getConfigModel()->__config->set("loadables.$id", $fn);

    return $this;
  }

  /**
   * Executes loadables sets or loadables by ID
   * 
   * @param array $ids List of loadables sets IDs or loadables IDs
   */
  public function load(array $ids = array())
  {
    // Get configuration data model
    $model = $this->__getConfigModel();

    // Handle loadables sets
    if ($model->get('loadables_sets')) {

      foreach ($ids as $loadable_set_key => $loadable_set_id) {
        if ($model->__config->hasKey("loadables_sets.$loadable_set_id") && !in_array($loadable_set_id, $this->__loaded)) {

          // Making sure we do not load a loadable 
          // with the same name as this loadable set
          unset($ids[$loadable_set_key]);

          // Loop through all loadables ids on this set
          foreach ($model->__config->get("loadables_sets.$loadable_set_id") as $loadable_id) {
              
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
    if ($model->get('loadables')) {

      foreach ($ids as $id) {
        if ($model->__config->hasKey("loadables.$id") && !in_array($id, $this->__loaded)) {

          $lac_feature = FeatureManager::getInstance()->get('mvc/model/loadables_auto_context');

          if ($lac_feature && $lac_feature->isEnabled())
            ContextManager::getInstance()->overrideCurrent('loadable/'. $this->getType() .'/'. $id);

          call_user_func_array($model->__config->get("loadables.$id"), array($this));

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
    // Get configuration data model
    $model = $this->__getConfigModel();

    $model->onContext($context_keys, function($model) use($loadables) {
      $model->load($loadables);
    });

    return $model;
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
    $this->__resetQuery();

    // Setting placeholder for data to return
    $data = null;

    // Get current context global $post
    if (is_null($ids)) {
        
      if (ContextManager::getInstance()->is('single')) {
          
        global $post;

        return new self($post);
      }
    }

    else {

      // Get current query object
      $query = $this->getQuery();

      // Set post type argument
      $query->postType($this->getType());

      // Change status to inherit on attachments
      if ($this->getType() == 'attachment')
        $query->status('inherit');

      // Get results
      $data = $query->find($ids, $options['keep_order']);

      if ($data) {
          
        if (is_object($data)) {
          
          $data = new self($data);
        }

        elseif (is_array($data)) {
          foreach ($data as $key => $post) {
              
            $data[$key] = new self($post);
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
    // Get current query object
    $query = $this->getQuery();

    // Add post type as final argument
    $query->postType($this->getType());

    // Change status to inherit on attachments
    if ($this->getType() == 'attachment')
      $query->status('inherit');

    // Get query results
    $items = $query->findAll($args);

    // Apply model modifications
    if ($items) {
      foreach ($items as $key => $item) {
        $items[$key] = new self($item);
      }
    }

    return $items;
  }

  /**
   * Returns current query object
   * 
   * @return Ponticlaro\Bebop\Db\Query
   */
  public function getQuery()
  {
    // Get configuration data model
    $model = $this->__getConfigModel();

    // Make sure we have a query object to be used
    if(!$model->__config->get('query'))
      $model->__enableQueryMode();

    return $model->__config->get('query');
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
    // Make sure we have a query object to be used
    $this->__enableQueryMode();

    call_user_func_array(array($this->getQuery(), $name), $args);

    return $this;
  }

  /**
   * Initializes data model
   * 
   * @param  string $type Data model type
   * @return void
   */
  private function __init($type)
  {
    $this->__type   = $type;
    $this->__config = new Collection([
      'context_mods'   => [],
      'loadables_sets' => [],
      'loadables'      => [],
      'query'          => null
    ]);

    static::$__config_models[$type] = $this;
  }

  /**
   * Checks if this model is the configuration one
   * 
   * @return boolean True if it is the config data model, false otherwise
   */
  private function __isConfigModel()
  {
    return is_null($this->__config) ? false : true;
  }

  /**
   * Returns configuration model for target post-type
   * 
   * @param  string $type Post-type name
   * @return object       Post-type configuration data model
   */
  private function __getConfigModel()
  {
    // Return current object if it is the config data model
    if ($this->__isConfigModel())
      return $this;

    // Get configuration data model
    $type = $this->getType();

    if (!isset(static::$__config_models[$type]))
      static::$__config_models[$type] = new self($type);

    return static::$__config_models[$type];
  }

  /**
   * Calls the function that applies initialization modifications
   * 
   * @param  object $item Object to be modified
   * @return void
   */
  private function __applyInitMods(&$item)
  {
    // Get configuration data model
    $model = $this->__getConfigModel();

    if (!is_null($model->__config->get('init_mods')))
      call_user_func_array($model->__config->get('init_mods'), array($item));
  }

  /**
   * 
   * Executes any function that exists for the current context
   * 
   * @param class $item WP_Post instance converted into an instance of the current class
   */
  private function __applyContextMods(&$item)
  {
    // Get configuration data model
    $model = $this->__getConfigModel();

    // Get current environment
    $context     = ContextManager::getInstance();
    $context_key = $context->getCurrent();

    // Execute current environment function
    if (!is_null($model->__config->get('context_mods'))) {

      // Exact match for the current environment
      if ($model->__config->hasKey("context_mods.$context_key")) {
          
        call_user_func_array($model->__config->get("context_mods.$context_key"), array($item));
      } 

      // Check for partial matches
      else {

        foreach ($model->__config->get('context_mods') as $key => $fn) {
        
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
  private function __enableQueryMode()
  {
    // Get current query object
    $query = $this->__getConfigModel()->__config->get('query');

    if (is_null($query) || $query->wasExecuted())
      $this->__resetQuery();
  }

  /**
   * Destroys current query and creates a new one
   * 
   * @return void
   */
  private function __resetQuery()
  {
    $this->__getConfigModel()->__config->set('query', new Query);
  }
}