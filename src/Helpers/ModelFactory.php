<?php

namespace Ponticlaro\Bebop\Mvc\Helpers;

class ModelFactory extends \Ponticlaro\Bebop\Common\Patterns\FactoryAbstract {

	/**
	 * List of manufacturable classes
	 * 
	 * @var array
	 */
	protected static $manufacturable = array(
		'post'       => 'Ponticlaro\Bebop\Mvc\Models\Post',
		'page'       => 'Ponticlaro\Bebop\Mvc\Models\Page',
		'attachment' => 'Ponticlaro\Bebop\Mvc\Models\Media',
		'media'      => 'Ponticlaro\Bebop\Mvc\Models\Media'
	);
}