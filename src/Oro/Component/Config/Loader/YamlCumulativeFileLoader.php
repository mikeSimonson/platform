<?php

namespace Oro\Component\Config\Loader;

use Oro\Component\Config\CumulativeResource;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlCumulativeFileLoader extends CumulativeFileLoader
{
    /**
     * {@inheritdoc}
     */
    protected function loadFile($file)
    {
        return $this->parseFile($file);
    }

    /**
     * Parses a YAML file.
     *
     * @param string $file Path to a file
     * @param string[] $importedPaths already parsed paths, for circular check
     * @return array|null
     * @throws \InvalidArgumentException When loading of YAML file returns error
     */
    protected function parseFile($file, $importedPaths = [])
    {
        try {
            $configData = Yaml::parse(file_get_contents($file)) ?: [];

            if (array_key_exists('imports', $configData) && is_array($configData['imports'])) {
                if (count($importedPaths) === 0) {
                    $importedPaths[] = $file; // for checking circular self-import
                }

                $parent = new \SplFileInfo($file);
                $imports = $configData['imports'];
                unset($configData['imports']);

                foreach ($imports as $importData) {
                    if (array_key_exists('resource', $importData)) {
                        $import = new \SplFileInfo($parent->getPath() . DIRECTORY_SEPARATOR . $importData['resource']);
                        $importPath = $import->getRealPath();

                        if (in_array($importPath, $importedPaths, true)) {
                            $importedPaths[] = $importPath; // for a complete tree in the exception message

                            throw new \InvalidArgumentException(
                                sprintf('Circular import detected in for "%s".', implode(' >> ', $importedPaths))
                            );
                        }

                        $importedPaths[] = $importPath;
                        $configData = array_merge_recursive($configData, $this->parseFile($importPath, $importedPaths));
                    }
                }
            }

            return $configData;
        } catch (ParseException $e) {
            $e->setParsedFile($file);
            throw new \InvalidArgumentException(sprintf('Unable to parse file "%s".', $file), $e->getCode(), $e);
        }
    }
    
    public function isResourceFresh($bundleClass, $bundleDir, $bundleAppDir, CumulativeResource $resource, $timestamp)
    {
        $resourcesToCheck = $this->addImportedResourcesFrom($resource);

        foreach($resourcesToCheck as $resourceToCheck) {

            if (!parent::isResourceFresh($bundleClass, $bundleDir, $bundleAppDir, $resource, $timestamp)) {

                return false;
            }
        }

        return true;
    }

    private function addImportedResourcesFrom(CumulativeResource $resource, $resources = [])
    {
        $file = $resource->getResource();
        $parent = new \SplFileInfo($file);
        $resources[] = $resource;
        $configData = Yaml::parse(file_get_contents($file)) ?: [];
        if (array_key_exists('imports', $configData) && is_array($configData['imports'])) {
            foreach($configData['imports'] as $importData) {
                if (array_key_exists('resource', $importData)) {
                    $import = new \SplFileInfo($parent->getPath() . DIRECTORY_SEPARATOR . $importData['resource']);
                    $importPath = $import->getRealPath();
                    $cumulativeResource = new CumulativeResource($importPath, new CumulativeResourceLoaderCollection());
                    $resources =  array_merge_recursive($resources, $this->addImportedResourcesFrom($cumulativeResource, $resources));
                }
            }
        }

        return $resources;
    }
}
