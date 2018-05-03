<?php

require_once 'phing/Task.php';


/**
 * VersionTask
 *
 * Parsing version numbers from PHP Class definition.
 *
 * @author      Martin Takáč <martin@takac.name>
 * @using
 *		<taco.version releasetype="version" src="source/main/lib/schema-manage/Taco/Tools/SchemaManage/Definition.php" property="build.version"/>
 */
class TacoVersionTask extends Task
{
    /**
     * Property for Releasetype
     * @var string $releasetype
     */
    private $releasetype;

    /**
     * Property for File
     * @var PhingFile file
     */
    private $src;

    /**
     * Property to be set
     * @var string $property
     */
    private $property;

    /* Allowed Releastypes */
    const RELEASETYPE_VERSION = 'VERSION';
    const RELEASETYPE_MAJOR = 'MAJOR';
    const RELEASETYPE_MINOR = 'MINOR';
    const RELEASETYPE_BUGFIX = 'BUGFIX';

    /**
     * Set Property for Releasetype (Minor, Major, Bugfix)
     * @param string $releasetype
     */
    function setReleasetype($releasetype)
    {
        $this->releasetype = strtoupper($releasetype);
    }

    /**
     * Set Property for File containing versioninformation
     * @param PhingFile $file
     */
    function setSrc($file)
    {
        $this->src = $file;
    }

    /**
     * Set name of property to be set
     * @param $property
     * @return void
     */
    function setProperty($property)
    {
        $this->property = $property;
    }

    /**
     * Main-Method for the Task
     *
     * @return void
     * @throws BuildException
     */
    function main()
    {
        // check supplied attributes
        $this->checkReleasetype();
        $this->checkFile();
        $this->checkProperty();

        // read file
        $content = \Taco\Tools\SchemaManage\Definition::VERSION;

        // get new version
        $newVersion = $this->getVersion($content);

        // publish new version number as property
        $this->project->setProperty($this->property, $newVersion);

    }

    /**
     * Returns new version number corresponding to Release type
     *
     * @param  string $filecontent
     * @return string
     */
    private function getVersion($filecontent)
    {
        // init
        $newVersion = '';

        // Extract version
        list($major, $minor, $bugfix) = explode(".", $filecontent);

        // Return new version number
        switch ($this->releasetype) {
            case self::RELEASETYPE_VERSION:
				return "{$major}.{$minor}";
            case self::RELEASETYPE_MAJOR:
				return $major;
            case self::RELEASETYPE_MINOR:
				return $minor;
            case self::RELEASETYPE_BUGFIX:
				return $bugfix;
        }
    }

    /**
     * checks releasetype attribute
     * @return void
     * @throws BuildException
     */
    private function checkReleasetype()
    {
        // check Releasetype
        if (is_null($this->releasetype)) {
            throw new BuildException('releasetype attribute is required', $this->location);
        }
        // known releasetypes
        $releaseTypes = array(
            self::RELEASETYPE_VERSION,
            self::RELEASETYPE_MAJOR,
            self::RELEASETYPE_MINOR,
            self::RELEASETYPE_BUGFIX
        );

        if (!in_array($this->releasetype, $releaseTypes)) {
            throw new BuildException(sprintf(
                'Unknown Releasetype %s..Must be one of Major, Minor or Bugfix',
                $this->releasetype
            ), $this->location);
        }
    }

    /**
     * checks file attribute
     * @return void
     * @throws BuildException
     */
    private function checkFile()
    {
        // check File
        if ($this->src === null ||
            strlen($this->src) == 0
        ) {
            throw new BuildException('You must specify a file containing the version number', $this->location);
        }

        require_once($this->src);
        $content = \Taco\Tools\SchemaManage\Definition::VERSION;
        if (strlen($content) == 0) {
            throw new BuildException(sprintf('Supplied file %s is empty', $this->file), $this->location);
        }

        // check for three-part number
        $split = explode('.', $content);
        if (count($split) !== 3) {
            throw new BuildException('Unknown version number format', $this->location);
        }
    }


    /**
     * checks property attribute
     * @return void
     * @throws BuildException
     */
    private function checkProperty()
    {
        if (is_null($this->property) ||
            strlen($this->property) === 0
        ) {
            throw new BuildException('Property for publishing version number is not set', $this->location);
        }
    }
}
