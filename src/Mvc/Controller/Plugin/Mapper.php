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
            $target = $map['to'];
            if (!empty($target['replace'])) {
                $target['replace'] = array_fill_keys($target['replace'], '');
                foreach ($target['replace'] as $query => &$value) {
                    if ($query === '__value__') {
                        continue;
                    }
                    $query = mb_substr($query, 1, -1);
                    if (isset($input[$query])) {
                        $value = $input[$query];
                    }
                }
                unset($value);
            }

            $query = $map['from'];
            if ($query === '~') {
                $value = '';
            } else {
                if (!isset($input[$query])) {
                    continue;
                }
                $value = $input[$query];
            }

            $this->simpleExtract
                ? $this->simpleExtract($value, $target, $query)
                : $this->appendValueToTarget($value, $target);
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
            $target = $map['to'];
            if (!empty($target['replace'])) {
                $target['replace'] = array_fill_keys($target['replace'], '');
                foreach ($target['replace'] as $query => &$value) {
                    if ($query === '{__value__}') {
                        continue;
                    }
                    $nodeList = $xpath->query(mb_substr($query, 1, -1));
                    if (!$nodeList || !$nodeList->length) {
                        continue;
                    }
                    $value = $nodeList->item(0)->nodeValue;
                }
                unset($value);
            }

            $query = $map['from'];
            if ($query === '~') {
                $value = '';
                $this->simpleExtract
                    ? $this->simpleExtract($value, $target, $query)
                    : $this->appendValueToTarget($value, $target);
            } else {
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
        unset($v['field'], $v['pattern'], $v['replace']);

        if (!empty($target['pattern'])) {
            $target['replace']['{__value__}'] = $value;
            $value = str_replace(array_keys($target['replace']), array_values($target['replace']), $target['pattern']);
        }

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

    /**
     * Create a flat array from a recursive array.
     *
     * @example
     * ```
     * // The following recursive array:
     * 'video' => [
     *      'dataformat' => 'jpg',
     *      'bits_per_sample' => 24;
     * ]
     * // is converted into:
     * [
     *     'video.dataformat' => 'jpg',
     *     'video.bits_per_sample' => 24,
     * ]
     * ```
     *
     * @param array $data
     * @return array
     */
    protected function flatArray(array $array)
    {
        $flatArray = [];
        $this->_flatArray($array, $flatArray);
        return $flatArray;
    }

    /**
     * Recursive helper to flat an array with separator ".".
     *
     * @todo Find a way to keep the last level of array (list of subjects…).
     *
     * @param array $array
     * @param array $flatArray
     * @param string $keys
     */
    private function _flatArray(array &$array, &$flatArray, $keys = null)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $this->_flatArray($value, $flatArray, $keys . '.' . $key);
            } else {
                $flatArray[trim($keys . '.' . $key, '.')] = $value;
            }
        }
    }
}
