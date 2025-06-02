<?php declare(strict_types=1);

namespace DynamicItemSets\Form\Element;

use Omeka\Form\Element\ArrayTextarea;

class ArrayTextareaQueries extends ArrayTextarea
{
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
            $strings[$key] = $query;
        }
        return $strings;
    }
}
