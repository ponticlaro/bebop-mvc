<?php

namespace Ponticlaro\Bebop\Mvc\Helpers;

use Ponticlaro\Bebop\Common\Collection;
use Ponticlaro\Bebop\Mvc\Model;

class FeatureManager extends \Ponticlaro\Bebop\Common\Patterns\SingletonAbstract {

  /**
   * Models collection
   * 
   * @var Ponticlaro\Bebop\Common\Collection object
   */
  protected $models;

  /**
   * Instantiates models manager
   * 
   */
  protected function __construct()
  {
    $this->models = new Collection;
  }

  /**
   * Adds a single model
   * 
   * @param Model $model Model object to be added
   */
  public function add(Model $model)
  {
    $this->models->set($model->getType(), $model);

    return $this;
  }

  /**
   * Returns model object
   * 
   * @param  string $type Type of the target model
   * @return object       Target model object
   */
  public function get($type)
  {
    if (!$this->exists($type)
      $this->add(new Model($type));

    return $this->models->get($type);
  } 

  /**
   * Returns all models
   * 
   * @return array List containing all existing models
   */
  public function getAll()
  {
    return $this->models->getAll();
  } 

  /**
   * Checks if there is a model with the target type
   * 
   * @param  string $type Type of the target model
   * @return bool         True if exists, false otherwise
   */
  public function exists($type)
  {
    return $this->models->hasKey($type);
  }
}