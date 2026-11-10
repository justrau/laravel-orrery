<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use UnitEnum;

/**
 * The reflection audit: each method that a class and one of its
 * parents both declare must keep the signature of the nearest parent
 * declaration. It compares the parameter names, their order, their defaults,
 * the variadics, the by-reference flags, the parameter types and the return
 * types. An override with fewer (or more) parameters than its parent is a
 * mismatch.
 *
 * One exception: an override may add a return type when the parent method
 * declares none (PHP accepts this as covariance). The audit does not count it
 * as a mismatch, but reports it in `addedReturnTypes` as `Class::method(): type`,
 * so a test can hold the additions to a reviewed list. When the parent declares
 * a return type (or a tentative one), a different return type is a mismatch.
 *
 * Constructors are not inspected: PHP applies no signature rule to them, and
 * nothing calls a constructor through the parent's contract. The package queue
 * forwards its constructor arguments with `...$args`.
 */
final class OverrideSignatureAudit
{
    /**
     * @param  iterable<class-string>  $classes
     * @return array{inspected: list<class-string>, mismatches: list<string>, addedReturnTypes: list<string>}
     */
    public static function audit(iterable $classes): array
    {
        $inspected = [];
        $mismatches = [];
        $added = [];

        foreach ($classes as $class) {
            $inspected[] = $class;
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName() || $method->isConstructor()) {
                    continue;
                }

                $parent = self::nearestParentDeclaration($reflection, $method->getName());

                if ($parent !== null) {
                    array_push($mismatches, ...self::compare($method, $parent));

                    if ($method->hasReturnType() && ! $parent->hasReturnType() && ! $parent->hasTentativeReturnType()) {
                        $added[] = sprintf("%s::%s(): %s", $reflection->getName(), $method->getName(), self::type($method->getReturnType()));
                    }
                }
            }
        }

        return ['inspected' => $inspected, 'mismatches' => $mismatches, 'addedReturnTypes' => $added];
    }

    private static function nearestParentDeclaration(ReflectionClass $class, string $name): ?ReflectionMethod
    {
        $parent = $class->getParentClass();

        if ($parent === false || ! $parent->hasMethod($name)) {
            return null;
        }

        $method = $parent->getMethod($name);

        // A private parent method is not inherited, so the child does not override it.
        return $method->isPrivate() ? null : $method;
    }

    /**
     * @return list<string>
     */
    private static function compare(ReflectionMethod $override, ReflectionMethod $parent): array
    {
        $subject = sprintf('%s::%s()', $override->getDeclaringClass()->getName(), $override->getName());
        $origin = sprintf('(parent %s::%s())', $parent->getDeclaringClass()->getName(), $parent->getName());
        $mismatches = [];

        $ours = $override->getParameters();
        $theirs = $parent->getParameters();

        if (count($ours) < count($theirs)) {
            $mismatches[] = sprintf('%s declares %d parameters, fewer than the parent\'s %d (missing %s) %s',
                $subject, count($ours), count($theirs), self::names(array_slice($theirs, count($ours))), $origin);
        } elseif (count($ours) > count($theirs)) {
            $mismatches[] = sprintf('%s declares %d parameters, more than the parent\'s %d (extra %s) %s',
                $subject, count($ours), count($theirs), self::names(array_slice($ours, count($theirs))), $origin);
        }

        foreach (array_map(null, array_slice($ours, 0, count($theirs)), array_slice($theirs, 0, count($ours))) as $position => [$mine, $base]) {
            /** @var ReflectionParameter $mine */
            /** @var ReflectionParameter $base */
            $at = sprintf('%s parameter %d ($%s)', $subject, $position + 1, $mine->getName());

            if ($mine->getName() !== $base->getName()) {
                $mismatches[] = sprintf('%s: the name differs from the parent name $%s %s', $at, $base->getName(), $origin);
            }

            if ($mine->isVariadic() !== $base->isVariadic()) {
                $mismatches[] = sprintf('%s: variadic %s, the parent variadic %s %s', $at, self::flag($mine->isVariadic()), self::flag($base->isVariadic()), $origin);
            }

            if ($mine->isPassedByReference() !== $base->isPassedByReference()) {
                $mismatches[] = sprintf('%s: by reference %s, the parent by reference %s %s', $at, self::flag($mine->isPassedByReference()), self::flag($base->isPassedByReference()), $origin);
            }

            $myDefault = self::defaultOf($mine);
            $baseDefault = self::defaultOf($base);

            if ($myDefault !== $baseDefault) {
                $mismatches[] = sprintf('%s: default %s differs from the parent default %s %s', $at, $myDefault, $baseDefault, $origin);
            }

            $myType = self::type($mine->getType());
            $baseType = self::type($base->getType());

            if ($myType !== $baseType) {
                $mismatches[] = sprintf('%s: type %s differs from the parent type %s %s', $at, $myType, $baseType, $origin);
            }
        }

        $myReturn = self::returnType($override);
        $baseReturn = self::returnType($parent);

        // A return type added where the parent declares none is reported by audit() in addedReturnTypes.
        if ($myReturn !== $baseReturn && $baseReturn !== '(none)') {
            $mismatches[] = sprintf('%s: return type %s differs from the parent return type %s %s', $subject, $myReturn, $baseReturn, $origin);
        }

        return $mismatches;
    }

    /**
     * The default as a PHP literal, or "(none)" for a required or variadic parameter.
     */
    private static function defaultOf(ReflectionParameter $parameter): string
    {
        if (! $parameter->isDefaultValueAvailable()) {
            return '(none)';
        }

        return self::literal($parameter->getDefaultValue());
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof UnitEnum => $value::class.'::'.$value->name,
            is_object($value) => 'new '.$value::class,
            is_array($value) => '['.implode(', ', array_map(
                static fn (mixed $key, mixed $item): string => self::literal($key).' => '.self::literal($item),
                array_keys($value),
                $value,
            )).']',
            default => var_export($value, true),
        };
    }

    private static function returnType(ReflectionMethod $method): string
    {
        if ($method->hasReturnType()) {
            return self::type($method->getReturnType());
        }

        if ($method->hasTentativeReturnType()) {
            return 'tentative '.self::type($method->getTentativeReturnType());
        }

        return '(none)';
    }

    private static function type(?ReflectionType $type): string
    {
        return $type === null ? '(none)' : (string) $type;
    }

    private static function flag(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param  array<ReflectionParameter>  $parameters
     */
    private static function names(array $parameters): string
    {
        return implode(', ', array_map(static fn (ReflectionParameter $p): string => '$'.$p->getName(), $parameters));
    }
}
