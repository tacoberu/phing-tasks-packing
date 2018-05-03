<?php


/**
 * @author Martin Takáč <martin@takac.name>
 */
class TacoPackSectionElement
{

	/**
	 * @var string
	 */
	private $name;


	/**
	 * @var string
	 */
	private $value;


	/**
	 * @param string $value
	 */
	function setValue($value)
	{
		$this->value = $value;
	}



	/**
	 * @param string $name
	 */
	function setName($name)
	{
		$this->name = $name;
	}



	/**
	 * @return string
	 */
	function getName()
	{
		return $this->name;
	}



	/**
	 * Return array of
	 *
	 * @return string|array
	 */
	function getValue()
	{
		return $this->value;
	}



	/**
	 * @return string|array
	 */
	function toArray()
	{
		return $this->value;
	}

}
