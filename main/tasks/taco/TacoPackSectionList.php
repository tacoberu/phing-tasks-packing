<?php

require_once __dir__ . '/TacoPackSectionElement.php';


/**
 * @author Martin Takáč <martin@takac.name>
 */
class TacoPackSectionList
{

	/**
	 * @var array
	 */
	private $sections = array();


	/**
	 * @return TacoPackMetadataElement
	 */
	function createSection()
	{
		return ($this->sections[] = new TacoPackMetadataElement());
	}



	/**
	 * @return array
	 */
	function toArray()
	{
		$sections = array();

		foreach ($this->sections as $element) {
			$sections[$element->getName()] = $element->toArray();
		}

		return $sections;
	}



	function getSection($name, $default = Null)
	{
		$name = strtolower($name);
		foreach ($this->sections as $element) {
			if (strtolower($element->getName()) == $name) {
				return $element;
			}
		}
		return $default;
	}

}
