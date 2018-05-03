<?php

require_once __dir__ . '/TacoPackMetadataElement.php';

/**
 * @author Martin Takáč <martin@takac.name>
 */
class TacoPackMetadata
{

	/**
	 * @var array
	 */
	protected $elements = array();


	/**
	 * @return TacoPackMetadataElement
	 */
	function createElement()
	{
		return ($this->elements[] = new TacoPackMetadataElement());
	}



	/**
	 * @return array
	 */
	function toArray()
	{
		$metadata = array();

		foreach ($this->elements as $element) {
			$metadata[$element->getName()] = $element->toArray();
		}

		return $metadata;
	}



	function getProperty($name, $default = Null)
	{
		$name = strtolower($name);
		foreach ($this->elements as $element) {
			if (strtolower($element->getName()) == $name) {
				return $element->toArray();
			}
		}
		return $default;
	}
}
