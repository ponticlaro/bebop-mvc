<?php

namespace Ponticlaro\Bebop\Mvc\Models;

use Ponticlaro\Bebop\Common\Collection;
use Ponticlaro\Bebop\Common\ContextManager;
use Ponticlaro\Bebop\Common\FeatureManager;
use Ponticlaro\Bebop\Db\Query;
use Ponticlaro\Bebop\Mvc\Helpers\ModelFactory;

class Post {

    /**
     * Holds child classes instances with their model customizations
     * 
     * @var array
     */
    private static $instances = array();

    /**
     * Post type name
     * 
     * @var string
     */
    protected static $__type = 'post';

    /**
     * Used to define which properties are exposed from the raw post object.
     * It can be one of the following values:
     * 
     * - true: model WILL expose ALL raw properties of the original post
     * - false: model WILL NOT expose ANY raw properties of the original post
     * - array: model WILL expose properties listed on this array
     * 
     * @var mixed
     */
    protected static $__raw_properties = true;

    /**
     * Current query instance
     * 
     * @var Ponticlaro\Bebop\Db\Query;
     */
    protected $query;

    /**
     * Function to be executed on instantiation
     * 
     * @var string
     */
    protected $init_mods;

    /**
     * List with model context based modifications
     * 
     * @var object Ponticlaro\Bebop\Common\Collection;
     */
    protected $context_mods;

    /**
     * List with model loadables sets
     * 
     * @var object Ponticlaro\Bebop\Common\Collection;
     */
    protected $loadables_sets;

    /**
     * List with model loadables
     * 
     * @var object Ponticlaro\Bebop\Common\Collection;
     */
    protected $loadables;

    /**
     * List of already loaded loadables sets and loadables
     * 
     * @var array
     */
    protected $loaded = [];

    /**
     * Instantiates new model by inheriting all the $post properties
     * 
     * @param WP_Post $post
     */
    final public function __construct($post = null, array $options = array())
    {   
        // Set default options
        $default_options = array(
            'config_instance' => false
        );

        // Merge default options with input
        $options = array_merge($default_options, $options);

        // Create configuration instance
        if ($options['config_instance']) {

            $this->context_mods   = (new Collection())->disableDottedNotation();
            $this->loadables_sets = (new Collection())->disableDottedNotation();
            $this->loadables      = (new Collection())->disableDottedNotation();

            // Add class to factory
            ModelFactory::set(static::$__type, get_called_class());
        }

        // Create data instance
        elseif ($post instanceof \WP_Post) {

            $this->raw = new \stdClass;
            $this->ID  = $post->ID;

            if (!in_array($post->post_type, array('attachment', 'revision', 'nav_menu')))
                $this->permalink = get_permalink($this->ID);

            foreach ((array) $post as $key => $value) {

                if (static::$__raw_properties) {
                    
                    // Expose only whitelisted raw properties
                    if (is_array(static::$__raw_properties)) {
                        
                        // Directly assign property name, if whitelisted
                        if (in_array($key, static::$__raw_properties)) {

                            $this->{$key} = $value;
                        }

                        // Use associative array key as property name, if whitelisted
                        if (array_key_exists($key, static::$__raw_properties)) {

                            $this->{static::$__raw_properties[$key]} = $value;
                        }
                    }

                    // Expose all raw properties
                    else {

                        $this->{$key} = $value;
                    }
                }

                // Add all properties to this instance raw property
                if ($key != 'ID')
                    $this->raw->{$key} = $value;
            }

            static::__applyInitMods($this, $post);
            static::__applyContextMods($this, $post);

            unset($this->raw);
        }
    }

    /**
     * Creates an instance of the currently called class
     * 
     * @return Ponticlaro\Bebop\Mvc\Model
     */
    public static function create(\WP_Post $post = null, array $options = array())
    {
        return new static($post, $options);
    }

    /**
     * Gets configuration instance for the current child model
     * 
     * @return Ponticlaro\Bebop\Mvc\Models\post
     */
    protected static function __getInstance()
    {
        $class = get_called_class();

        if (!isset(self::$instances[$class])) {

            // Create configuration instance
            self::$instances[$class] = new static(null, ['config_instance' => true]);

            // Load model configuration
            $class::loadConfig();
        }

        return self::$instances[$class];
    }

    /**
     * Model configuration 
     * 
     */
    protected static function loadConfig()
    {
        // Nothing to do here
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
    }

    /**
     * Sets a function to be executed for every single item,
     * right after being fetched from the database
     * 
     * @param  callable $fn Function to be executed
     */
    public static function onInit($fn)
    {
        if (is_callable($fn))
            static::__getInstance()->init_mods = $fn;
    }

    /**
     * Adds a function to execute when the target context key is active
     * 
     * @param string $context_keys Target context keys
     * @param string $fn           Function to execute
     */
    public static function onContext($context_keys, $fn)
    {   
        if (is_callable($fn)) {

            // Get model configuration instance
            $instance = static::__getInstance();

            if (is_string($context_keys)) {
               
                $instance->context_mods->set($context_keys, $fn);
            }

            elseif (is_array($context_keys)) {
                
                foreach ($context_keys as $context_key) {
                   
                    $instance->context_mods->set($context_key, $fn);
                }
            }
        }
    }

    /**
     * Adds a set of functions to load optional content or apply optional modifications 
     * 
     * @param string   $id Loadable set ID
     * @param callable $fn List of loadables to load
     */
    public static function addLoadableSet($id, array $loadables)
    {
        static::__getInstance()->loadables_sets->set($id, $loadables);
    }

    /**
     * Adds a single function to load optional content or apply optional modifications 
     * 
     * @param string   $id Loadable ID
     * @param callable $fn Loadable function
     */
    public static function addLoadable($id, $fn)
    {
        static::__getInstance()->loadables->set($id, $fn);
    }

    /**
     * Executes loadables sets or loadables by ID
     * 
     * @param array $ids List of loadables sets IDs or loadables IDs
     */
    public function load(array $ids = array())
    {
        // Get model configuration instance
        $instance = static::__getInstance();

        // Handle loadables sets
        if (!is_null($instance->loadables_sets)) {
            foreach ($ids as $loadable_set_key => $loadable_set_id) {
                if ($instance->loadables_sets->hasKey($loadable_set_id) && !in_array($loadable_set_id, $this->loaded)) {

                    // Making sure we do not load a loadable 
                    // with the same name as this loadable set
                    unset($ids[$loadable_set_key]);

                    // Loop through all loadables ids on this set
                    foreach ($instance->loadables_sets->get($loadable_set_id) as $loadable_id) {
                        
                        // Add loadable ID, if not already present
                        if ($loadable_id != $loadable_set_id && !in_array($loadable_id, $ids))
                            $ids[] = $loadable_id;
                    }

                    // Mark loadable set as loaded
                    $this->loaded[] = $loadable_set_id;
                }
            }
        }

        // Handle loadables
        if (!is_null($instance->loadables)) {
            foreach ($ids as $id) {
                if ($instance->loadables->hasKey($id) && !in_array($id, $this->loaded)) {

                    $lac_feature = FeatureManager::getInstance()->get('mvc/model/loadables_auto_context');

                    if ($lac_feature && $lac_feature->isEnabled())
                        ContextManager::getInstance()->overrideCurrent('loadable/'. static::$__type .'/'. $id);

                    call_user_func_array($instance->loadables->get($id), array($this));

                    if ($lac_feature && $lac_feature->isEnabled())
                        ContextManager::getInstance()->restoreCurrent();

                    // Mark loadable as loaded
                    $this->loaded[] = $id;
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
    public static function loadOnContext($context_keys, array $loadables)
    {
        static::onContext($context_keys, function($model) use($loadables) {
            $model->load($loadables);
        });
    }

    /**
     * Apply all post modifications 
     * 
     * @param  \WP_Post $post
     * @return object         Instance of the current class
     */
    private static function __applyModelMods(\WP_Post $post)
    {   
        return new static($post);
    }

    /**
     * Calls the function that applies initialization modifications
     * 
     * @param  object $item Object to be modified
     * @return void
     */
    private static function __applyInitMods(&$item, $raw_post)
    {
        // Get model configuration instance
        $instance = static::__getInstance();

        if (!is_null($instance->init_mods))
            call_user_func_array($instance->init_mods, array($item, $raw_post));
    }

    /**
     * 
     * Executes any function that exists for the current context
     * 
     * @param class $item WP_Post instance converted into an instance of the current class
     */
    protected function __applyContextMods(&$item, $raw_post)
    {
        // Get model configuration instance
        $instance = static::__getInstance();

        // Get current environment
        $context     = ContextManager::getInstance();
        $context_key = $context->getCurrent();

        // Execute current environment function
        if (!is_null($instance->context_mods)) {

            // Exact match for the current environment
            if ($instance->context_mods->hasKey($context_key)) {
                
                call_user_func_array($instance->context_mods->get($context_key), array($item, $raw_post));
            } 

            // Check for partial matches
            else {

                foreach ($instance->context_mods->getAll() as $key => $fn) {
                
                    if ($context->is($key))
                        call_user_func_array($fn, array($item, $raw_post));
                }
            }
        }
    }

    /**
     * Returns entries by ID
     * 
     * @param  mixed $ids        Single ID or array of IDs
     * @param  bool  $keep_order True if posts order should match the order of $ids, false otherwise
     * @return mixed             Single object or array of objects
     */
    public static function find($ids = null, $keep_order = true)
    {
        // Make sure we have a clean query object to be used
        static::__resetQuery();

        // Setting placeholder for data to return
        $data = null;

        // Get current context global $post
        if (is_null($ids)) {
            
            if (ContextManager::getInstance()->is('single')) {
                
                global $post;

                return static::__applyModelMods($post);
            }
        }

        else {

            // Get model configuration instance
            $instance = static::__getInstance();

            // Set post type argument
            $instance->query->postType(static::$__type);

            // Change status to inherit on attachments
            if (static::$__type == 'attachment')
               $instance->query->status('inherit');

            // Get results
            $data = $instance->query->find($ids, $keep_order);

            if ($data) {
                
                if (is_object($data)) {
                
                    $data = static::__applyModelMods($data);
                }

                elseif (is_array($data)) {
                    
                    foreach ($data as $key => $post) {
                        
                        $data[$key] = static::__applyModelMods($post);
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
    public static function findAll(array $args = array())
    {   
        // Make sure we have a query object to be used
        static::__enableQueryMode();

        // Get model configuration instance
        $instance = static::__getInstance();

        // Add post type as final argument
        $instance->query->postType(static::$__type);

        // Change status to inherit on attachments
        if (static::$__type == 'attachment')
           $instance->query->status('inherit');

        // Get query results
        $items = $instance->query->findAll($args);

        // Save query meta data
        $instance->query_meta = $instance->query->getMeta();

        // Apply model modifications
        if ($items) {
            foreach ($items as $key => $item) {

                $items[$key] = static::__applyModelMods($item);
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
        // Get model configuration instance
        $instance = static::__getInstance();

        // Make sure we have a query object to be used
        if(is_null($instance->query))
            static::__enableQueryMode();

        return $instance->query;
    }

    /**
     * Enables query mode and creates a new query
     * 
     * @return void
     */
    private static function __enableQueryMode()
    {
        // Get model configuration instance
        $instance = static::__getInstance();

        if (is_null($instance->query) || $instance->query->wasExecuted()) {

            $instance->query = new Query;
        }
    }

    /**
     * Destroys current query and creates a new one
     * 
     * @return void
     */
    private static function __resetQuery()
    {
        // Get model configuration instance
        static::__getInstance()->query = new Query;
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

        // Get model configuration instance
        $instance = static::__getInstance();

        call_user_func_array(array($instance->query, $name), $args);

        return $instance;
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
        // Get model configuration instance
        $instance = static::__getInstance();

        if (!is_null($instance->query))
            call_user_func_array(array($instance->query, $name), $args);

        return $instance;
    }
}