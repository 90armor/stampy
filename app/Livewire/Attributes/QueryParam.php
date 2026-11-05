<?php

namespace App\Livewire\Attributes;

use Livewire\Attributes\Url;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Livewire's #[Url], made safe against whatever is typed into the address bar
 * (Phase 6h). Livewire writes the query value straight into the typed
 * property, so a scalar where the property is a list (`/attendance?timing=late`),
 * a list where it's a string (`?q[]=x`) or a parameter with no value
 * (`?status`) threw a TypeError — a 500. This reads the raw query value and
 * fits it to the property's declared type instead:
 *
 * - array: a scalar becomes a one-item list; a list keeps its scalar items, as
 *   strings, and drops nested ones;
 * - string: a scalar as typed (never JSON-decoded, so `?q=123` stays "123");
 *   a list is ignored;
 * - bool: true/false/1/0/on/off/yes/no; anything else is ignored.
 *
 * Ignored means the property keeps its default. Which values are allowed —
 * a known status, a real date, a department in scope — is still the
 * component's to check: this only guarantees the type. Every query-string
 * property in the app uses this, never Livewire's own #[Url]
 * (QueryParameterJunkTest).
 */
#[\Attribute]
class QueryParam extends Url
{
    public function setPropertyFromQueryString()
    {
        $missing = uniqid('__no_exist__', true);
        $raw = $this->getFromUrlQueryString($this->urlName(), $missing);

        if ($raw === $missing) {
            return;
        }

        $type = (new ReflectionProperty($this->getComponent(), $this->getName()))->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        $value = match ($name) {
            'array' => $this->toList($raw),
            'string' => is_array($raw) ? $missing : (string) $raw,
            'bool' => is_string($raw) ? filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $missing : $missing,
            default => $missing,
        };

        if ($value !== $missing) {
            $this->setValue($value);
        }
    }

    /** @return list<string> */
    private function toList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return $raw === null || $raw === '' ? [] : [(string) $raw];
        }

        return array_values(array_unique(array_map('strval', array_filter($raw, 'is_scalar'))));
    }
}
