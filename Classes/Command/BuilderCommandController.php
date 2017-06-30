<?php
namespace FluidTYPO3\Builder\Command;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Claus Due <claus@namelesscoder.net>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

use FluidTYPO3\Builder\Service\ExtensionService;
use FluidTYPO3\Builder\Service\SyntaxService;
use FluidTYPO3\Builder\Utility\GlobUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

/**
 * Class BuilderCommandController
 * @package FluidTYPO3\Builder\Command
 */
class BuilderCommandController extends CommandController
{

    /**
     * @var SyntaxService
     */
    protected $syntaxService;

    /**
     * @var ExtensionService
     */
    protected $extensionService;

    /**
     * @param SyntaxService $syntaxService
     * @return void
     */
    public function injectSyntaxService(SyntaxService $syntaxService)
    {
        $this->syntaxService = $syntaxService;
    }

    /**
     * @param ExtensionService $extensionService
     * @return void
     */
    public function injectExtensionService(ExtensionService $extensionService)
    {
        $this->extensionService = $extensionService;
    }

    /**
     * Syntax check Fluid template
     *
     * Checks one template file, all templates in
     * an extension or a sub-path (which can be used
     * with an extension key for a relative path).
     * If left out, it will lint ALL templates in
     * EVERY local extension.
     *
     * @param string $extension Optional extension key (if path is set too it will apply to sub-folders in extension)
     * @param string $path file or folder path (if extensionKey is included, path is relative to this extension)
     * @param string $extensions If provided, this CSV list of file extensions are considered Fluid templates
     * @param boolean $verbose If TRUE outputs more information about each file check - default is to only output errors
     * @throws \RuntimeException
     * @return void
     */
    public function fluidSyntaxCommand($extension = null, $path = null, $extensions = 'html,xml,txt', $verbose = false)
    {
        $verbose = (boolean) $verbose;
        if (null !== $extension) {
            $this->assertEitherExtensionKeyOrPathOrBothAreProvidedOrExit($extension, $path);
            $path = GlobUtility::getRealPathFromExtensionKeyAndPath($extension, $path);
            $files = GlobUtility::getFilesRecursive($path, $extensions);
        } else {
            // no extension key given, let's lint it all
            $files = [];
            /** @var ExtensionService $extensionService */
            $extensionService = $this->objectManager->get('FluidTYPO3\Builder\Service\ExtensionService');
            $extensionInformation = $extensionService->getComputableInformation();
            foreach ($extensionInformation as $extensionName => $extensionInfo) {
                // Syntax service declines linting of inactive extensions
                if (0 === intval($extensionInfo['installed']) || 'System' === $extensionInfo['type']) {
                    continue;
                }
                $path = GlobUtility::getRealPathFromExtensionKeyAndPath($extensionName, null);
                $files = array_merge($files, GlobUtility::getFilesRecursive($path, $extensions));
            }
        }
        $files = array_values($files);
        $errors = false;
        $this->response->setContent(
            'Performing a syntax check on fluid templates (types: ' . $extensions . '; path: ' . $path . ')' . LF
        );
        $this->response->send();
        foreach ($files as $filePathAndFilename) {
            $basePath = str_replace(PATH_site, '', $filePathAndFilename);
            $result = $this->syntaxService->syntaxCheckFluidTemplateFile($filePathAndFilename);
            if (null !== $result->getError()) {
                $this->response->appendContent('[ERROR] File ' . $basePath . ' has an error: ' . LF);
                $this->response->appendContent(
                    $result->getError()->getMessage() . ' (' . $result->getError()->getCode() . ')' . LF
                );
                $this->response->send();
                $errors = true;
            } elseif (true === $verbose) {
                $namespaces = $result->getNamespaces();
                $this->response->appendContent(
                    'File is compilable: ' . ($result->getCompilable() ? 'YES' : 'NO (WARNING)') . LF
                );
                if ($result->getLayoutName()) {
                    $this->response->appendContent('File has layout (' . $result->getLayoutName() . ')' . LF);
                } else {
                    $this->response->appendContent('File DOES NOT reference a Layout' . LF);
                }
                $this->response->appendContent(
                    'File has ' . count($namespaces) . ' namespace(s)' .
                    (0 < count($namespaces) ? ': ' . $result->getNamespacesFlattened() : '') . LF
                );
                $this->response->appendContent('[OK] File  ' . $basePath . ' is valid.' . LF);
                $this->response->send();
            }
            $this->response->setContent(LF);
        }
        $this->stop($files, $errors, $verbose);
    }

    /**
     * Syntax check PHP code
     *
     * Checks PHP source files in $path, if extension
     * key is also given, only files in that path relative
     * to that extension are checked.
     *
     * @param string $extension Optional extension key (if path is set too it will apply to sub-folders in extension)
     * @param string $path file or folder path (if extensionKey is included, path is relative to this extension)
     * @param boolean $verbose If TRUE outputs more information about each file check - default is to only output errors
     * @return void
     */
    public function phpsyntaxCommand($extension = null, $path = null, $verbose = false)
    {
        $verbose = (boolean) $verbose;
        $this->assertEitherExtensionKeyOrPathOrBothAreProvidedOrExit($extension, $path);
        if (null !== $extension) {
            $results = $this->syntaxService->syntaxCheckPhpFilesInExtension($extension);
        } else {
            $results = $this->syntaxService->syntaxCheckPhpFilesInPath($path);
        }
        $errors = false;
        foreach ($results as $filePathAndFilename => $result) {
            $result = $this->syntaxService->syntaxCheckPhpFile($filePathAndFilename);
            if (null !== $result->getError()) {
                $errors = true;
                $this->response->setContent(
                    '[ERROR] ' . $result->getError()->getMessage() . ' (' . $result->getError()->getCode() . ')' . LF
                );
            }
        }
        $this->stop($results, $errors, $verbose);
    }

    /**
     * Lists installed Extensions. The output defaults to text and is new-line separated.
     *
     * @param boolean $detail If TRUE, the command will give detailed information such as version and state
     * @param boolean $active If TRUE, the command will give information about active extensions only
     * @param boolean $inactive If TRUE, the command will give information about inactive extensions only
     * @param boolean $json If TRUE, the command will return a json object-string
     * @throws \Exception
     * @return void
     */
    public function listCommand($detail = false, $active = null, $inactive = false, $json = false)
    {
        $detail = (boolean) $detail;
        $active = (boolean) $active;
        $inactive = (boolean) $inactive;
        $json = (boolean) $json;

        $format = 'text';
        if (true === $json) {
            $format = 'json';
        }
        if ($active) {
            $state = ExtensionService::STATE_ACTIVE;
        } elseif ($inactive) {
            $state = ExtensionService::STATE_INACTIVE;
        } else {
            $state = ExtensionService::STATE_ALL;
        }

        $this->response->setContent(
            $this->extensionService->getPrintableInformation($format, $detail, $state)
        );
    }

    /**
     * Builds a ProviderExtension
     *
     * The resulting extension will contain source code
     * and configuration options needed by the various
     * toggles. Each of these toggles enable/disable
     * generation of source code and configuration for
     * that particular feature.
     *
     * @param string $extensionKey The extension key which should be generated. Must not exist.
     * @param string $author The author of the extension, in the format "Name Lastname <name@example.com>" with optional
     *                       company name, in which case form is "Name Lastname <name@example.com>, Company Name"
     * @param string $title Title of the resulting extension, by default "Provider extension for $enabledFeaturesList"
     * @param string $description Description for extension, by default "Provider extension for $enabledFeaturesList"
     * @param boolean $useVhs If TRUE, adds the VHS extension as dependency - recommended, on by default
     * @param boolean $pages If TRUE, generates basic files for implementing Fluid Page templates
     * @param boolean $content IF TRUE, generates basic files for implementing Fluid Content templates
     * @param boolean $controllers If TRUE, generates controllers for each enabled feature. Enabling $backend will
     *                             always generate a controller regardless of this toggle.
     * @param boolean $dry If TRUE performs a dry run without writing files;reports which files would have been written
     * @param boolean $verbose If FALSE, suppresses a lot of the otherwise output messages (to STDOUT)
     * @return void
     */
    public function providerExtensionCommand(
        $extensionKey,
        $author,
        $title = null,
        $description = null,
        $useVhs = true,
        $pages = true,
        $content = true,
        $controllers = true,
        $dry = false,
        $verbose = true
    ) {
        $useVhs = (boolean) $useVhs;
        $pages = (boolean) $pages;
        $content = (boolean) $content;
        $controllers = (boolean) $controllers;
        $verbose = (boolean) $verbose;
        $dry = (boolean) $dry;
        $extensionGenerator = $this->extensionService->buildProviderExtensionGenerator(
            $extensionKey,
            $author,
            $title,
            $description,
            $controllers,
            $pages,
            $content,
            $useVhs
        );
        $extensionGenerator->setDry($dry);
        $extensionGenerator->setVerbose($verbose);
        $extensionGenerator->generate();
    }

    /**
     * Black hole
     *
     * @return void
     */
    protected function errorCommand()
    {
    }

    /**
     * @param string $extension
     * @param string $path
     * @return void
     */
    private function assertEitherExtensionKeyOrPathOrBothAreProvidedOrExit($extension, $path)
    {
        if (null === $extension && null === $path) {
            $this->response->setContent('Either "extension" or "path" or both must be specified' . LF);
            $this->response->send();
            $this->response->setExitCode(128);
            $this->forward('error');
        }
    }

    /**
     * @param array $files
     * @param boolean $errors
     * @param boolean $verbose
     */
    protected function stop($files, $errors, $verbose)
    {
        if (true === (boolean) $verbose) {
            if (false === $errors) {
                $this->response->setContent('No errors encountered - ' . count($files) . ' file(s) are all okay' . LF);
            } else {
                $this->response->setContent('Errors were detected - review the summary above' . LF);
                $this->response->setExitCode(1);
            }
        }
        $this->response->send();
    }

    /**
     * Get all class names inside this namespace and return them as array.
     *
     * @param string $combinedExtensionKey Extension Key with (possibly) leading Vendor Prefix
     * @return array
     */
    protected function getClassNamesInExtension($combinedExtensionKey)
    {
        $allViewHelperClassNames = [];
        list ($vendor, $extensionKey) = $this->getRealExtensionKeyAndVendorFromCombinedExtensionKey(
            $combinedExtensionKey
        );
        $path = ExtensionManagementUtility::extPath($extensionKey, 'Classes/ViewHelpers/');
        $filesInPath = GeneralUtility::getAllFilesAndFoldersInPath([], $path, 'php');
        foreach ($filesInPath as $filePathAndFilename) {
            $className = $this->getRealClassNameBasedOnExtensionAndFilenameAndExistence(
                $combinedExtensionKey,
                $filePathAndFilename
            );
            if (class_exists($className)) {
                $parent = $className;
                while ($parent = get_parent_class($parent)) {
                    if ($parent === 'TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper'
                        || $parent === 'TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper') {
                        array_push($allViewHelperClassNames, $className);
                    }
                }
            }
        }
        $affectedViewHelperClassNames = [];
        foreach ($allViewHelperClassNames as $viewHelperClassName) {
            $classReflection = new \ReflectionClass($viewHelperClassName);
            if ($classReflection->isAbstract() === true) {
                continue;
            }
            $namespace = $classReflection->getNamespaceName();
            if (strncmp($namespace, $viewHelperClassName, strlen($namespace)) === 0) {
                $affectedViewHelperClassNames[] = $viewHelperClassName;
            }
        }
        sort($affectedViewHelperClassNames);
        return $affectedViewHelperClassNames;
    }

    /**
     * Returns the true class name of the ViewHelper as defined
     * by the extensionKey (which may be vendorname.extensionkey)
     * and the class name. If vendorname is used, namespaced
     * classes are assumed. If no vendorname is used a namespaced
     * class is first attempted, if this does not exist the old
     * Tx_ prefixed class name is tried. If this too does not exist,
     * an Exception is thrown.
     *
     * @param string $combinedExtensionKey
     * @param string $filename
     * @return string
     * @throws \Exception
     */
    protected function getRealClassNameBasedOnExtensionAndFilenameAndExistence($combinedExtensionKey, $filename)
    {
        list ($vendor, $extensionKey) = $this->getRealExtensionKeyAndVendorFromCombinedExtensionKey(
            $combinedExtensionKey
        );
        $filename = str_replace(
            ExtensionManagementUtility::extPath($extensionKey, 'Classes/ViewHelpers/'),
            '',
            $filename
        );
        $stripped = substr($filename, 0, -4);
        if ($vendor) {
            $classNamePart = str_replace('/', '\\', $stripped);
            $className = sprintf(
                '%s\\%s\\ViewHelpers\\%s',
                $vendor,
                ucfirst(GeneralUtility::underscoredToLowerCamelCase($extensionKey)),
                $classNamePart
            );
        } else {
            $classNamePart = str_replace('/', '_', $stripped);
            $className = sprintf(
                '%s\\ViewHelpers\\%s',
                ucfirst(GeneralUtility::underscoredToLowerCamelCase($extensionKey)),
                $classNamePart
            );
        }
        return $className;
    }

    /**
     * @param string $extensionKey
     * @return array
     */
    protected function getRealExtensionKeyAndVendorFromCombinedExtensionKey($extensionKey)
    {
        if (false !== strpos($extensionKey, '.')) {
            list ($vendor, $extensionKey) = explode('.', $extensionKey);
            if ('TYPO3' === $vendor) {
                $vendor = 'TYPO3\CMS';
            }
        } else {
            $vendor = null;
        }
        $extensionKey = strtolower($extensionKey);
        return [$vendor, $extensionKey];
    }
}
