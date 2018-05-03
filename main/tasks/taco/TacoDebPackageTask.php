<?php

require_once 'phing/tasks/system/MatchingTask.php';
require_once __dir__ . '/TacoPackMetadata.php';
require_once __dir__ . '/TacoPackSectionList.php';
require_once __dir__ . '/TacoPackSectionElement.php';


use Taco\Utils\Process;

/**
 * Build DEB Package
 *
 * @author      Martin Takáč <martin@takac.name>
 * @using
		<taco.debpackage
				name="${phing.project.name}"
				platform="centos"
				tempdir="${dir.build.packaging}"
				destfile="${dir.build.packaging}/${phing.project.name}-${build.version}-${build.release}.deb"
				>
			<fileset dir="${dir.source}/debian"/>
			<metadata>
				<element name="version" value="${build.version}" />
				<element name="release" value="${build.release}" />
				<element name="licence" value="MIT" />
				<element name="group" value="System Environment/Base" />
				<element name="summary" value="Deb configuration for tacoberu repository." />
				<element name="description" value="${package.description}" />
				<element name="changelog" value="${package.changelog}" />
				<element name="authors">
					<element name="${package.maintainer.name}">
						<element name="e-mail" value="${package.maintainer.email}" />
					</element>
				</element>
			</metadata>
			<sections>
				<section name="Depends">zip</section>
			</sections>
		</taco.debpackage>
 */
class TacoDebPackageTask extends MatchingTask
{
	const HASHTYPE_MD5 = 0;
	const HASHTYPE_SHA1 = 1;

	/**
	 * @var string
	 */
	private $name = 'app';


	/**
	 * @var string
	 */
	private $platform = 'deb';


    /**
     * Specify which hash algorithm to use.
     *   0 = MD5
     *   1 = SHA1
     *
     * @var integer $hashtype
     */
    private $hashtype= self::HASHTYPE_MD5;


	/**
	 * @var PhingFile
	 */
	private $destDir;


	/**
	 * Base directory, from where local package paths will be calculated.
	 *
	 * @var PhingFile
	 */
	private $baseDirectory;


	/**
	 * @var PhingFile
	 */
	private $tempDirectory;


	/**
	 * @var array
	 */
	private $filesets = array();


	/**
	 * @var array
	 */
	private $filelists = array();


	/**
	 * @var PharMetadata
	 */
	private $metadata = null;


	/**
	 * @var PharSectionList
	 */
	private $sections = null;


	private $fileCopyMap = array();
	private $dirCopyMap = array();
	private $checksums = array();


	/**
	 * A name of project.
	 *
	 * @param string
	 */
	function setName($s)
	{
		$this->name = $s;
	}



	/**
	 * A platform as fedora, suse, etc
	 *
	 * @param string
	 */
	function setPlatform($s)
	{
		$this->platform = $s;
	}



	/**
	 * alias for setTofile()
	 *
	 * @param PhingFile $file
	 */
	function setDestDir(PhingFile $file)
	{
		$this->destDir = $file;
	}



	/**
	 * Set the toFile. We have to manually take care of the
	 * type that is coming due to limited type support in php
	 * in and convert it manually if neccessary.
	 *
	 * @param  string/object  The dest file. Either a string or an PhingFile object
	 * @return void
	 * @access public
	 */
	function setTodir(PhingFile $file)
	{
		$this->destDir = $file;
	}



	/**
	 * @param PhingFile $baseDirectory
	 */
	function setTempDir(PhingFile $baseDirectory)
	{
		$this->tempDirectory = $baseDirectory;
	}



	/**
	 * Base directory, which will be deleted from each included file (from path).
	 * Paths with deleted basedir part are local paths in package.
	 *
	 * @param PhingFile $baseDirectory
	 */
	function setBaseDir(PhingFile $baseDirectory)
	{
		$this->baseDirectory = $baseDirectory;
	}



	/**
	 * @return FileSet
	 */
	function createFileSet()
	{
		$this->fileset = new IterableFileSet();
		$this->filesets[] = $this->fileset;
		return $this->fileset;
	}



	/**
	 * Nested creator, creates a FileSet for this task
	 *
	 * @param FileSet $fileset Set of files to copy
	 *
	 * @return void
	 */
	function addFileSet(FileSet $fs)
	{
		$this->filesets[] = $fs;
	}



	/**
	 * Nested creator, adds a set of files (nested fileset attribute).
	 *
	 * @access  public
	 * @return  object  The created filelist object
	 */
	function createFileList()
	{
		$num = array_push($this->filelists, new FileList());
		return $this->filelists[$num-1];
	}



	/**
	 * @return TacoPackMetadata
	 */
	function createMetadata()
	{
		return ($this->metadata = new TacoPackMetadata());
	}



	/**
	 * @return TacoPackSectionList
	 */
	function createSections()
	{
		return ($this->sections = new TacoPackSectionList());
	}



	/**
	 * @throws BuildException
	 */
	function main()
	{
		$this->cleanDirectory();
		$this->prepareDirectory();

		$destDir = new PhingFile($this->getWorkingDirectory(), 'usr');

		// process filelists
		foreach($this->filelists as $fl) {
			$fromDir  = $fl->getDir($this->project);
			$srcFiles = $fl->getFiles($this->project);
			$srcDirs  = array($fl->getDir($this->project));
			$this->_scan($fromDir, $destDir, $srcFiles, $srcDirs);
		}

		// process filesets
		foreach($this->filesets as $fs) {
			$ds = $fs->getDirectoryScanner($this->project);
			$fromDir  = $fs->getDir($this->project);
			$srcFiles = $ds->getIncludedFiles();
			$srcDirs  = $ds->getIncludedDirectories();
			$this->_scan($fromDir, $destDir, $srcFiles, $srcDirs);
		}

		// go and copy the stuff usr
		$this->doCopySource($destDir);

		// make files to DEBIAN
		$destDir = $this->getWorkingDirectory();
		$this->doBuildControl($destDir);
		$this->doBuildChangelog($destDir);
		$this->doBuildCopyright($destDir);
		$this->doBuildMd5sums($destDir);

		// compile
		$currdir = getcwd();
		@chdir($this->getWorkingDirectory()->getParent());
		list($return, $output) = $this->executeCommand();

		@chdir($currdir);
		if ($return) {
			throw new BuildException("Build DEB package exited with code $return.");
		}

		$packFile = $this->getPackageFileName();
		if (! $packFile->exists()) {
			throw new BuildException("Cannot find DEB package $packFile.");
		}

		$packFile->copyTo(new PhingFile($this->destDir, $packFile->getName()));
	}



	// -- PRIVATE ------------------------------------------------------



	private function buildCommand()
	{
		$cmd = array();
		$cmd[] = 'fakeroot';
		$cmd[] = 'dpkg-deb';
		$cmd[] = '-b ' . $this->platform;
		$cmd[] = $this->getPackageFileName();

		return implode(' ', $cmd);
	}



	/**
	 * Executes the command and returns return code and output.
	 *
	 * @return array array(return code, array with output)
	 */
	private function executeCommand()
	{
		$realCommand = $this->buildCommand();
		//~ $this->log("Executing command: " . $realCommand, $this->logLevel);
		$ps = new Process\Exec($realCommand);
		$res = $ps->run($realCommand);
		return array($res->code, implode(PHP_EOL, $res->content));
	}



	private function getPackageFileName()
	{
		$version = $this->metadata->getProperty('Version', '0.1');
		$release = $this->metadata->getProperty('Release', '1');
		return new PhingFile($this->getWorkingDirectory()->getParent(), "{$this->name}-{$version}-{$release}.deb");
	}



	private function buildSection($name)
	{
		if ($section = $this->sections->getSection($name)) {
			return $section->getValue();
		}
		switch ($name) {
			case 'Preparing':
				return 'echo "Nothing to prepare"';
			case 'Build':
				return 'echo "Nothing to build"';
			default:
				return "# $name";
		}
	}



	private function getWorkingDirectory()
	{
		return new PhingFile($this->tempDirectory, $this->platform);
	}



	private function getDebianDirectory()
	{
		return new PhingFile($this->getWorkingDirectory(), 'DEBIAN');
	}



	/**
	 * Odstranit původní adresář.
	 */
	private function cleanDirectory()
	{
		$d = $this->getWorkingDirectory();
		if ($d->exists()) {
			$d->delete(True);
		}
	}



	/**
	 * Připravit adresáře pro build.
	 */
	private function prepareDirectory()
	{
		$d = $this->getWorkingDirectory();
		$d->mkdir(0755);
		$d = $this->getDebianDirectory();
		$d->mkdir(0755);
	}



	/**
	 * Compares source files to destination files to see if they
	 * should be copied.
	 *
	 * @access  private
	 * @return  void
	 */
	private function _scan(&$fromDir, &$toDir, &$files, &$dirs)
	{
		/* mappers should be generic, so we get the mappers here and
		pass them on to builMap. This method is not redundan like it seems */
		$mapper = null;
		//~ if ($this->mapperElement !== null) {
			//~ $mapper = $this->mapperElement->getImplementation();
		//~ }
		//~ else if ($this->flatten) {
			//~ $mapper = new FlattenMapper();
		//~ }
		//~ else {
			//~ $mapper = new IdentityMapper();
		//~ }
		$mapper = new IdentityMapper();
		$this->buildMap($fromDir, $toDir, $files, $mapper, $this->fileCopyMap);
		$this->buildMap($fromDir, $toDir, $dirs, $mapper, $this->dirCopyMap);
	}



	/**
	 * Builds a map of filenames (from->to) that should be copied
	 *
	 * @access  private
	 * @return  void
	 */
	private function buildMap(&$fromDir, &$toDir, &$names, &$mapper, &$map)
	{
		$overwrite = True;
		$toCopy = null;
		if ($overwrite) {
			$v = array();
			foreach($names as $name) {
				$result = $mapper->main($name);
				if ($result !== null) {
					$v[] = $name;
				}
			}
			$toCopy = $v;
		}
		else {
			$ds = new SourceFileScanner($this);
			$toCopy = $ds->restrict($names, $fromDir, $toDir, $mapper);
		}

		for ($i=0,$_i=count($toCopy); $i < $_i; $i++) {
			$src  = new PhingFile($fromDir, $toCopy[$i]);
			$mapped = $mapper->main($toCopy[$i]);
			$dest = new PhingFile($toDir, $mapped[0]);
			$map[$src->getAbsolutePath()] = $dest->getAbsolutePath();
		}
	}



	/**
	 * Actually copies the files
	 *
	 * @access  private
	 * @return  void
	 * @throws  BuildException
	 */
	private function doCopySource($destDir)
	{
		$fileUtils = new FileUtils();
		$overwrite = True;
		$preserveLMT = True;
		$filterChains = array();
		$mode = 0644;
		$preservePermissions = True;

		// These "slots" allow filters to retrieve information about the currently-being-process files
		$fromSlot = $this->getRegisterSlot("currentFromFile");
		$fromBasenameSlot = $this->getRegisterSlot("currentFromFile.basename");

		$toSlot = $this->getRegisterSlot("currentToFile");
		$toBasenameSlot = $this->getRegisterSlot("currentToFile.basename");

		$mapSize = count($this->fileCopyMap);
		$total = $mapSize;

		if ($mapSize > 0) {
			$this->log("Copying ".$mapSize." file".(($mapSize) === 1 ? '' : 's')." to ". $destDir->getAbsolutePath());
			// walks the map and actually copies the files
			$count=0;
			foreach($this->fileCopyMap as $from => $to) {
				if ($from === $to) {
					$this->log("Skipping self-copy of " . $from, $this->verbosity);
					$total--;
					continue;
				}
				//~ $this->log("From ".$from." to ".$to, $this->verbosity);
				try {
					$fromFile = new PhingFile($from);
					$toFile = new PhingFile($to);

					$fromSlot->setValue($fromFile->getPath());
					$fromBasenameSlot->setValue($fromFile->getName());

					$toSlot->setValue($toFile->getPath());
					$toBasenameSlot->setValue($toFile->getName());

					$fileUtils->copyFile($fromFile, $toFile,
							$overwrite,
							$preserveLMT,
							$filterChains,
							$this->getProject(),
							$mode,
							$preservePermissions
							);

					// Calculate checksum
					$this->checksums[] = $this->calculateChecksump($toFile);

					$count++;
				}
				catch (IOException $ioe) {
					$this->logError("Failed to copy " . $from . " to " . $to . ": " . $ioe->getMessage());
				}
			}
		}
	}



	private function calculateChecksump($file)
	{
		$filename = $file->getPathWithoutBase($this->getWorkingDirectory());
        switch ((int)$this->hashtype) {
			case self::HASHTYPE_MD5:
				return md5_file($file, false) . ' ' . $filename;
				break;
			case self::HASHTYPE_SHA1:
				return sha1_file($file, false) . ' ' . $filename;
			default:
				throw new BuildException(
					sprintf('[FileHash] Unknown hashtype specified %d. Must be either 0 (=MD5) or 1 (=SHA1).',$this->hashtype));
		}
	}



	/**
	 * @return string
	 */
	private function getMaintainer()
	{
		if ($m = $this->metadata->getProperty('Maintainer')) {
			return $m;
		}
		if ($authors = $this->metadata->getProperty('authors')) {
			$names = array_keys($authors);
			$name = reset($names);
			$email = $authors[$name]['e-mail'];
			return "{$name} <{$email}>";
		}
	}



	/**
	 * @return string
	 */
	private function getAuthor()
	{
		if ($authors = $this->metadata->getProperty('authors')) {
			$names = array_keys($authors);
			return reset($names);
		}
	}



	private function doBuildControl($destDir)
	{
		$depends = '';
		if ($this->buildSection('Depends')) {
			$depends = 'Depeneds: ' . $this->buildSection('Depends');
		}
		$content = file_get_contents(__dir__ . '/template-debian_control');
		$content = strtr($content, array(
			'${Name}' => $this->name,
			// metadata
			'${License}' => $this->metadata->getProperty('licence', 'proprietary'),
			'${Summary}' => $this->metadata->getProperty('summary'),
			'${Version}' => $this->metadata->getProperty('Version', '0.1'),
			'${Release}' => $this->metadata->getProperty('Release', 0),
			'${Group}' => $this->metadata->getProperty('Group', 'FIXME'),
			'${Priority}' => $this->metadata->getProperty('Priority', 'optional'),
			'${BuildArch}' => $this->metadata->getProperty('BuildArch', 'all'),
			'${Description}' => $this->metadata->getProperty('Description'),
			'${Changelog}' => $this->metadata->getProperty('Changelog'),
			'${Author}' => $this->getAuthor(),
			'${Maintainer}' => $this->getMaintainer(),
			'${Homepage}' => $this->metadata->getProperty('Homepage'),
			// sections
			'${Depends}' => $depends,
		));
		file_put_contents(new PhingFile($this->getDebianDirectory(), 'control'), $content);
	}



	private function doBuildRules($destDir)
	{
		$content = "Source: {$this->name}\n";
		file_put_contents(new PhingFile($this->getDebianDirectory(), 'rules'), $content);
	}



	private function doBuildCopyright($destDir)
	{
		$content = file_get_contents($this->lookupLicence($this->metadata->getProperty('licence', 'proprietary')));
		$content = strtr($content, array(
			'${Year}' => date('Y'),
			'${Author}' => $this->getAuthor(),
		));
		file_put_contents(new PhingFile($this->getDebianDirectory(), 'copyright'), $content);
	}



	private function doBuildChangelog($destDir)
	{
		file_put_contents(new PhingFile($this->getDebianDirectory(), 'changelog'), $this->metadata->getProperty('Changelog'));
	}



	private function doBuildMd5sums($destDir)
	{
		file_put_contents(new PhingFile($this->getDebianDirectory(), 'md5sums'), implode(PHP_EOL, $this->checksums));
	}



	private function lookupLicence($name)
	{
		$file = __dir__ . '/../../../licence/' . $name;
		if ( ! file_exists($file)) {
			throw new BuildException(sprintf('Unknown licence %s.', $name));
		}
		return $file;
	}

}
