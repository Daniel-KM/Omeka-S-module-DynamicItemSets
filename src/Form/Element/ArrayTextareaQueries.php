<?php declare(strict_types=1);

namespace DynamicItemSets\Form\Element;

use Omeka\Form\Element\ArrayTextarea;

class ArrayTextareaQueries extends ArrayTextarea
{
    /**
     * @var bool
     */
    protected $removeArgumentsUseless = false;

    public function setOptions($options)
    {
        parent::setOptions($options);
        if (array_key_exists('remove_arguments_useless', $this->options)) {
            $this->setRemoveArgumentsUseless($this->options['remove_arguments_useless']);
        }
        return $this;
    }

    public function arrayToString($array)
    {
        if (is_string($array)) {
            return $array;
        }

        if (!$array) {
            return '';
        }

        $strings = [];
        if ($this->asKeyValue) {
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $value = http_build_query($value, '', '&', PHP_QUERY_RFC3986);
                }
                $strings[] = strlen((string) $value) ? "$key $this->keyValueSeparator $value" : $key;
            }
        } else {
            foreach ($array  as $key => $value) {
                if (is_array($value)) {
                    $value = http_build_query($value, '', '&', PHP_QUERY_RFC3986);
                }
                $strings[] = $value;
            }
        }

        return implode("\n", $strings);
    }

    public function stringToArray($string)
    {
        if (is_array($string)) {
            return $string;
        }

        if (!strlen($string)) {
            return [];
        }

        return $this->asKeyValue
            ? $this->stringToKeyQueries($string)
            : $this->stringToListQueries($string);
    }

    protected function stringToKeyQueries($string): array
    {
        $result = [];
        foreach ($this->stringToList($string) as $keyValue) {
            if (strpos($keyValue, $this->keyValueSeparator) === false) {
                $result[trim($keyValue)] = '';
            } else {
                [$key, $value] = array_map('trim', explode($this->keyValueSeparator, $keyValue, 2));
                $query = [];
                parse_str(ltrim((string) $value, "? \t\n\r\0\x0B"), $query);
                if ($this->removeArgumentsUseless) {
                    $query = $this->arrayFilterRecursiveEmpty($query);
                }
                $result[$key] = $query;
            }
        }
        return $result;
    }

    protected function stringToListQueries($string): array
    {
        $strings = parent::stringToList($string);
        foreach ($strings as $key => $string) {
            $query = [];
            parse_str(ltrim((string) $string, "? \t\n\r\0\x0B"), $query);
            if ($this->removeArgumentsUseless) {
                $query = $this->arrayFilterRecursiveEmpty($query);
            }
            $strings[$key] = $query;
        }
        return $strings;
    }

    /**
     * Clean an array recursively, removing empty values ("", null and []).
     *
     * "0" is a valid value, and the same for 0 and false.
     * It is mainly used to clean a url query.
     *
     * @param array $array The array is passed by reference.
     * @return array
     */
    public function arrayFilterRecursiveEmpty(array &$array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->arrayFilterRecursiveEmpty($value);
            }
            if (in_array($array[$key], ['', null, []], true)) {
                unset($array[$key]);
            }
        }
        return $array;
    }

    public function setRemoveArgumentsUseless($removeArgumentsUseless): self
    {
        $this->removeArgumentsUseless = (bool) $removeArgumentsUseless;
        return $this;
    }

    public function getRemoveArgumentsUseless(): bool
    {
        return $this->removeArgumentsUseless;
    }
}
