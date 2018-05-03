<?php

require_once 'phing/tasks/system/MatchingTask.php';
require_once __dir__ . '/TacoPackMetadata.php';
require_once __dir__ . '/TacoPackSectionList.php';
require_once __dir__ . '/TacoPackSectionElement.php';


use Taco\Utils\Process;

/**
 * Build RPM Package
 *
 * @author      Martin Takáč <martin@takac.name>
 * @using
		<taco.rpmpackage
				name="${phing.project.name}"
				platform="centos"
				tempdir="${dir.build.packaging}"
				destfile="${dir.build.packaging}/${phing.project.name}-${build.version}-${build.release}.rpm"
				>
			<fileset dir="${dir.source}/centos"/>
			<filelist dir="${dir.source}" files="RPM-GPG-KEY"/>
			<metadata>
				<element name="version" value="${build.version}" />
				<element name="release" value="${build.release}" />
				<element name="licence" value="MIT" />
				<element name="group" value="System Environment/Base" />
				<element name="summary" value="RPM configuration for tacoberu repository." />
				<element name="description" value="${package.description}" />
				<element name="changelog" value="${package.changelog}" />
				<element name="authors">
					<element name="${package.maintainer.name}">
						<element name="e-mail" value="${package.maintainer.email}" />
					</element>
				</element>
			</metadata>
			<sections>
				<section name="sources">
					Source0: RPM-GPG-KEY
					Source1: tacoberu.repo
				</section>
				<section name="install">
					rm -rf $RPM_BUILD_ROOT
					%{__install} -Dp -m0644 %{SOURCE0} %{buildroot}%{_sysconfdir}/pki/rpm-gpg/RPM-GPG-KEY-tacoberu
					%{__install} -Dp -m0644 %{SOURCE1} %{buildroot}%{_sysconfdir}/yum.repos.d/tacoberu.repo
				</section>
				<section name="clean">
					rm -rf $RPM_BUILD_ROOT
				</section>
				<section name="files">
					%defattr(-,root,root,-)
					%config(noreplace) %{_sysconfdir}/yum.repos.d/tacoberu*.repo
					%{_sysconfdir}/pki/rpm-gpg/RPM-GPG-KEY-tacoberu
				</section>
			</sections>
		</taco.rpmpackage>
 */
class TacoRpmPackageTask extends MatchingTask
{

	/**
	 * @var string
	 */
	private $name = 'app';


	/**
	 * @var string
	 */
	private $platform;


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

		$destDir = new PhingFile($this->getWorkingDirectory(), 'SOURCES');
		$destDir->mkdir(0777);

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

		// go and copy the stuff
		$this->doCopySource($destDir);

		// make spec-file
		$this->doSpecFile();

		$currdir = getcwd();
		@chdir($this->getWorkingDirectory());
		list($return, $output) = $this->executeCommand();

		@chdir($currdir);
		if ($return) {
			throw new BuildException("Build RPM package exited with code $return.");
		}

		$rpmFile = $this->getRpmFileName();
		if (! $rpmFile->exists()) {
			throw new BuildException("Cannot find RPM package $rpmFile.");
		}

		$rpmFile->copyTo(new PhingFile($this->destDir, $rpmFile->getName()));
	}



	// -- PRIVATE ------------------------------------------------------



	private function buildCommand()
	{
		$workDir = $this->getWorkingDirectory();
		$cmd = array();
		$cmd[] = '/usr/bin/rpmbuild';
		$cmd[] = '--target=' . $this->metadata->getProperty('BuildArch', 'noarch');
		$cmd[] = '--define "_topdir ' . $workDir . '"';
		$cmd[] = '--define "_builddir %{_topdir}/BUILDROOT"';
		$cmd[] = '--define "_rpmdir %{_topdir}/RPMS"';
		$cmd[] = '--define "_srcrpmdir %{_topdir}/SRPMS"';
		$cmd[] = '--define "_specdir %{_topdir}/SPECS"';
		$cmd[] = '--define "_sourcedir %{_topdir}/SOURCES"';

		//~ $cmd[] = '--define "_signature gpg"';
		//~ $cmd[] = '--define "_gpg_path ~/.gnupg"';
		//~ $cmd[] = '--define "_gpg_name Martin Takáč <martin@takac.name>"';
		if ($this->isSign()) {
			$cmd[] = '--sign';
		}
		$cmd[] = '-ba ' . $this->getSpecFileName();

		return implode(' ', $cmd);
	}



	/**
	 * Executes the command and returns return code and output.
	 *
	 * @return array array(return code, array with output)
	 */
	protected function executeCommand()
	{
		$realCommand = $this->buildCommand();
		//~ $this->log("Executing command: `$realCommand'.", $this->logLevel);
		$ps = new Process\Exec($realCommand);
		$res = $ps->run($realCommand);
		return array($res->code, implode(PHP_EOL, $res->content));
	}



	private function getSpecFileName()
	{
		$specDir = new PhingFile($this->getWorkingDirectory(), 'SPECS');
		$specDir->mkdir();
		return new PhingFile($specDir, $this->name . '.spec');
	}



	private function getRpmFileName()
	{
		$rpmDir = new PhingFile($this->getWorkingDirectory(), 'RPMS');
		$arch = $this->metadata->getProperty('BuildArch', 'noarch');
		$rpmDir = new PhingFile($rpmDir, $arch);
		$version = $this->metadata->getProperty('Version', '0.1');
		$release = $this->metadata->getProperty('Release', '1');
		return new PhingFile($rpmDir, "{$this->name}-{$version}-{$release}.{$arch}.rpm");
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



	private function isSign()
	{
		return True;
	}



	private function doSpecFile()
	{
		$content = file_get_contents(__dir__ . '/template-rpm-spec');
		$content = strtr($content, array(
			'${Name}' => $this->name,
			'${Year}' => date('Y'),
			// metadata
			'${License}' => $this->metadata->getProperty('licence', 'proprietary'),
			'${Summary}' => $this->metadata->getProperty('summary'),
			'${Version}' => $this->metadata->getProperty('Version', '0.1'),
			'${Release}' => $this->metadata->getProperty('Release', 0),
			'${Group}' => $this->metadata->getProperty('Group', 'FIXME'),
			'${BuildArch}' => $this->metadata->getProperty('BuildArch', 'noarch'),
			'${Description}' => $this->metadata->getProperty('Description'),
			'${Changelog}' => $this->metadata->getProperty('Changelog'),
			'${Author}' => $this->getAuthor(),
			'${Maintainer}' => $this->getMaintainer(),
			// sections
			'${Sources}' => $this->buildSection('Sources'),
			'${Preparing}' => $this->buildSection('Preparing'),
			'${Build}' => $this->buildSection('Build'),
			'${Install}' => $this->buildSection('Install'),
			'${Clean}' => $this->buildSection('Clean'),
			'${Files}' => $this->buildSection('Files'),
		));
		file_put_contents($this->getSpecFileName(), $content);
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
			case 'Install':
			case 'Clean':
				return 'rm -rf $RPM_BUILD_ROOT';
			default:
				return "# $name";
		}
	}



	private function getWorkingDirectory()
	{
		return new PhingFile($this->tempDirectory, $this->platform);
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
		$d->mkdir(0777);
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
		$mode = 0;
		$preservePermissions = True;

		// These "slots" allow filters to retrieve information about the currently-being-process files
		$fromSlot = $this->getRegisterSlot("currentFromFile");
		$fromBasenameSlot = $this->getRegisterSlot("currentFromFile.basename");

		$toSlot = $this->getRegisterSlot("currentToFile");
		$toBasenameSlot = $this->getRegisterSlot("currentToFile.basename");

		$mapSize = count($this->fileCopyMap);
		$total = $mapSize;

		/*/ handle empty dirs if appropriate
		if ($this->includeEmpty) {
			$count = 0;
			foreach ($this->dirCopyMap as $srcdir => $dest) {
				$s = new PhingFile((string) $srcdir);
				$d = new PhingFile((string) $dest);
				if (!$d->exists()) {

					// Setting source directory permissions to target
					// (On permissions preservation, the target directory permissions
					// will be inherited from the source directory, otherwise the 'mode'
					// will be used)
					$dirMode = ($this->preservePermissions ? $s->getMode() : $this->mode);

					// Directory creation with specific permission mode
					if (!$d->mkdirs($dirMode)) {
						$this->logError("Unable to create directory " . $d->__toString());
					}
					else {
						if ($this->preserveLMT) {
							$d->setLastModified($s->lastModified());
						}

						$count++;
					}
				}
			}
			if ($count > 0) {
				$this->log("Created ".$count." empty director" . ($count == 1 ? "y" : "ies") . " in " . $destDir->getAbsolutePath());
			}
		}//*/

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

					$count++;
				}
				catch (IOException $ioe) {
					$this->logError("Failed to copy " . $from . " to " . $to . ": " . $ioe->getMessage());
				}
			}
		}
	}


}
