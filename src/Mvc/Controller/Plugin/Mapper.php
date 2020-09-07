<?php

namespace AdvancedResourceTemplate\Mvc\Controller\Plugin;

use ArrayObject;
use DOMDocument;
use DOMXPath;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Extract data from a string with a mapping.
 */
class Mapper extends AbstractPlugin
{
    /**
     * @var \AdvancedResourceTemplate\Mvc\Controller\Plugin\MapperHelper
     */
    protected $mapperHelper;

    /**
     * Normalize a mapping.
     *
     * Mapping is either a list of xpath or json path mapped with properties:
     * [
     *     [/xpath/to/data => [field => dcterms:title]],
     *     [object.to.data => [field => dcterms:title]],
     * ]
     *
     * @var array
     */
    protected $mapping = [];

    /**
     * Only extract metadata, don't map them.
     *
     * @var bool
     */
    protected $simpleExtract = false;

    /**
     * @var ArrayObject
     */
    protected $result;

    /**
     * @return self
     */
    public function __invoke()
    {
        return $this;
    }

    public function setMapping(array $mapping)
    {
        $this->mapperHelper = $this->getController()->mapperHelper();
        $this->mapping = $this->normalizeMapping($mapping);
        return $this;
    }

    public function setSimpleExtract($simpleExtract)
    {
        $this->simpleExtract = (bool) $simpleExtract;
        return $this;
    }

    /**
     * Extract data from an url that returns a json.
     *
     * @param string $url
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function urlArray($url)
    {
        $content = file_get_contents($url);
        $content = json_decode($content, true);
        if (!is_array($content)) {
            return [];
        }
        return $this->array($content);
    }

    /**
     * Extract data from an url that returns an xml.
     *
     * @param string $url
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function urlXml($url)
    {
        $content = file_get_contents($url);
        if (empty($content)) {
            return [];
        }
        return $this->xml($content);
    }

    /**
     * Extract data from an array.
     *
     * @param array $input Array of metadata.
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function array(array $input)
    {
        if (empty($this->mapping)) {
            return [];
        }

        $this->result = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        foreach ($this->mapping as $map) {
            $query = $map['from'];
            $target = $map['to'];

            $queryMapping = explode('.', $query);
            $inputFields = $input;
            foreach ($queryMapping as $qm) {
                if (isset($inputFields[$qm])) {
                    $inputFields = $inputFields[$qm];
                }
            }

            if (!is_array($inputFields)) {
                $this->simpleExtract
                    ? $this->simpleExtract($inputFields, $target, $query)
                    : $this->appendValueToTarget($inputFields, $target);
            }
        }

        return $this->result->exchangeArray([]);
    }

    /**
     * Extract data from a xml string with a mapping.
     *
     * @param string $string
     * @return array A resource array by property, suitable for api creation
     * or update.
     */
    public function xml($xml)
    {
        if (empty($this->mapping)) {
            return [];
        }

        // Check if the xml is fully formed.
        $xml = trim($xml);
        if (strpos($xml, '<?xml ') !== 0) {
            $xml = '<?xml version="1.1" encoding="utf-8"?>' . $xml;
        }

        $this->result = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);

        // Register all namespaces to allow prefixes.
        $xpathN = new DOMXPath($doc);
        foreach ($xpathN->query('//namespace::*') as $node) {
            $xpath->registerNamespace($node->prefix, $node->nodeValue);
        }

        foreach ($this->mapping as $map) {
            $query = $map['from'];
            $target = $map['to'];
            $nodeList = $xpath->query($query);
            if (!$nodeList || !$nodeList->length) {
                continue;
            }

            // The answer has many nodes.
            foreach ($nodeList as $node) {
                $this->simpleExtract
                    ? $this->simpleExtract($node->nodeValue, $target, $query)
                    : $this->appendValueToTarget($node->nodeValue, $target);
            }
        }

        return $this->result->exchangeArray([]);
    }

    protected function simpleExtract($value, $target, $source)
    {
        $this->result[] = [
            'field' => $source,
            'target' => $target,
            'value' => $value,
        ];
    }

    protected function appendValueToTarget($value, $target)
    {
        $v = $target;
        switch ($v['type']) {
            default:
            case 'literal':
            // case strpos($v['type'], 'customvocab:') === 0:
                $v['@value'] = $value;
                $this->result[$target['field']][] = $v;
                break;
            case 'uri':
            case strpos($target['type'], 'valuesuggest:') === 0:
                $v['@id'] = $value;
                $this->result[$target['field']][] = $v;
                break;
            case 'resource':
            case 'resource:item':
            case 'resource:media':
            case 'resource:itemset':
                // The mapping from an external service cannot be an internal
                // resource.
                break;
        }
    }

    protected function normalizeMapping(array $mapping)
    {
        foreach ($mapping as &$map) {
            $to = &$map['to'];
            $to['property_id'] = $this->mapperHelper->getPropertyId($to['field']);
            if (empty($to['type'])) {
                $to['type'] = 'literal';
            }
        }
        return $mapping;
    }
}
