<?php

require_once __dir__ . '/TacoPackMetadata.php';


/**
 * @author Martin Takáč <martin@takac.name>
 */
class TacoPackMetadataElement extends TacoPackMetadata
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
		/*
		 * Elements first!
		 */
		return (empty($this->elements) ? $this->value : $this->elements);
	}



    /**
     * Supporting the <echo>Message</echo> syntax.
     */
    function addText($value)
    {
		$value = trim($value);
		$value = implode(PHP_EOL, array_map('ltrim', explode(PHP_EOL, $value)));
        $this->value = $value;
    }



	/**
	 * @return string|array
	 */
	function toArray()
	{
		return (empty($this->elements) ? $this->value : parent::toArray());
	}

}
