<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * In Livewire's JS proxy ($wire) a public property shadows the public method
 * of the same name: wire:submit="review" read Leave\RequestModal's $review
 * array and called nothing, so the modal never reached its review step —
 * while every server-side test, which calls the method directly, passed.
 * No component may have a public property and method that share a name.
 */
class LivewireNamingTest extends TestCase
{
    public function test_no_component_has_a_public_property_and_method_with_the_same_name(): void
    {
        $clashes = [];

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! is_subclass_of($class, Component::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $methods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $method) => $method->class === $class)
                ->map(fn (ReflectionMethod $method) => strtolower($method->name));

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->class === $class && $methods->contains(strtolower($property->name))) {
                    $clashes[] = "{$class}::\${$property->name}";
                }
            }
        }

        $this->assertSame([], $clashes);
    }
}
