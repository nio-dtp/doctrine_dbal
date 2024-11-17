<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name\Parser;
use Doctrine\DBAL\Schema\Name\Parser\Identifier;
use Doctrine\Deprecations\Deprecation;

use function array_map;
use function count;
use function crc32;
use function dechex;
use function explode;
use function implode;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * The abstract asset allows to reset the name of all assets without publishing this to the public userland.
 *
 * This encapsulation hack is necessary to keep a consistent state of the database schema. Say we have a list of tables
 * array($tableName => Table($tableName)); if you want to rename the table, you have to make sure this does not get
 * recreated during schema migration.
 */
abstract class AbstractAsset
{
    protected string $_name = '';

    /**
     * Namespace of the asset. If none isset the default namespace is assumed.
     */
    protected ?string $_namespace = null;

    protected bool $_quoted = false;

    /** @var list<Identifier> */
    private array $identifiers = [];

    private bool $validateFuture = false;

    public function __construct(?string $name = null)
    {
        if ($name === null) {
            Deprecation::trigger(
                'doctrine/dbal',
                'https://github.com/doctrine/dbal/pull/6610',
                'Not passing $name to %s is deprecated.',
                __METHOD__,
            );

            return;
        }

        $this->_setName($name);
    }

    /**
     * Sets the name of this asset.
     *
     * @deprecated Use the constructor instead.
     */
    protected function _setName(string $name): void
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/6610',
            '%s is deprecated. Use the constructor instead.',
            __METHOD__,
        );

        $input = $name;

        if ($this->isIdentifierQuoted($name)) {
            $this->_quoted = true;
            $name          = $this->trimQuotes($name);
        }

        if (str_contains($name, '.')) {
            $parts            = explode('.', $name);
            $this->_namespace = $parts[0];
            $name             = $parts[1];
        }

        $this->_name = $name;

        $this->validateFuture = false;

        if ($input !== '') {
            $parser = new Parser();

            try {
                $identifiers = $parser->parse($input);
            } catch (Parser\Exception $e) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/6592',
                    'Unable to parse object name: %s.',
                    $e->getMessage(),
                );

                return;
            }
        } else {
            $identifiers = [];
        }

        switch (count($identifiers)) {
            case 0:
                $this->identifiers = [];

                return;
            case 1:
                $namespace = null;
                $name      = $identifiers[0];
                break;

            case 2:
                /** @psalm-suppress PossiblyUndefinedArrayOffset */
                [$namespace, $name] = $identifiers;
                break;

            default:
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/6592',
                    'An object name may consist of at most 2 identifiers (<namespace>.<name>), %d given.',
                    count($identifiers),
                );

                return;
        }

        $this->identifiers    = $identifiers;
        $this->validateFuture = true;

        $futureName      = $name->getValue();
        $futureNamespace = $namespace?->getValue();

        if ($this->_name !== $futureName) {
            Deprecation::trigger(
                'doctrine/dbal',
                'https://github.com/doctrine/dbal/pull/6592',
                'Instead of "%s", this name will be interpreted as "%s" in 5.0',
                $this->_name,
                $futureName,
            );
        }

        if ($this->_namespace === $futureNamespace) {
            return;
        }

        Deprecation::trigger(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/6592',
            'Instead of %s, the namespace in this name will be interpreted as %s in 5.0.',
            $this->_namespace !== null ? sprintf('"%s"', $this->_namespace) : 'null',
            $futureNamespace !== null ? sprintf('"%s"', $futureNamespace) : 'null',
        );
    }

    /**
     * Is this asset in the default namespace?
     */
    public function isInDefaultNamespace(string $defaultNamespaceName): bool
    {
        return $this->_namespace === $defaultNamespaceName || $this->_namespace === null;
    }

    /**
     * Gets the namespace name of this asset.
     *
     * If NULL is returned this means the default namespace is used.
     */
    public function getNamespaceName(): ?string
    {
        return $this->_namespace;
    }

    /**
     * The shortest name is stripped of the default namespace. All other
     * namespaced elements are returned as full-qualified names.
     */
    public function getShortestName(?string $defaultNamespaceName): string
    {
        $shortestName = $this->getName();
        if ($this->_namespace === $defaultNamespaceName) {
            $shortestName = $this->_name;
        }

        return strtolower($shortestName);
    }

    /**
     * Checks if this asset's name is quoted.
     */
    public function isQuoted(): bool
    {
        return $this->_quoted;
    }

    /**
     * Checks if this identifier is quoted.
     */
    protected function isIdentifierQuoted(string $identifier): bool
    {
        return isset($identifier[0]) && ($identifier[0] === '`' || $identifier[0] === '"' || $identifier[0] === '[');
    }

    /**
     * Trim quotes from the identifier.
     */
    protected function trimQuotes(string $identifier): string
    {
        return str_replace(['`', '"', '[', ']'], '', $identifier);
    }

    /**
     * Returns the name of this schema asset.
     */
    public function getName(): string
    {
        if ($this->_namespace !== null) {
            return $this->_namespace . '.' . $this->_name;
        }

        return $this->_name;
    }

    /**
     * Gets the quoted representation of this asset but only if it was defined with one. Otherwise
     * return the plain unquoted value as inserted.
     */
    public function getQuotedName(AbstractPlatform $platform): string
    {
        $keywords = $platform->getReservedKeywordsList();
        $parts    = $normalizedParts = [];

        foreach (explode('.', $this->getName()) as $identifier) {
            $isQuoted = $this->_quoted || $keywords->isKeyword($identifier);

            if (! $isQuoted) {
                $parts[]           = $identifier;
                $normalizedParts[] = $platform->normalizeUnquotedIdentifier($identifier);
            } else {
                $parts[]           = $platform->quoteSingleIdentifier($identifier);
                $normalizedParts[] = $identifier;
            }
        }

        $name = implode('.', $parts);

        if ($this->validateFuture) {
            $futureParts = array_map(static function (Identifier $identifier) use ($platform): string {
                $value = $identifier->getValue();

                if (! $identifier->isQuoted()) {
                    $value = $platform->normalizeUnquotedIdentifier($value);
                }

                return $value;
            }, $this->identifiers);

            if ($normalizedParts !== $futureParts) {
                Deprecation::trigger(
                    'doctrine/dbal',
                    'https://github.com/doctrine/dbal/pull/6592',
                    'Relying on implicitly quoted identifiers preserving their original case is deprecated. '
                        . 'The current name %s will become %s in 5.0. '
                        . 'Please quote the name if the case needs to be preserved.',
                    $name,
                    implode('.', array_map([$platform, 'quoteSingleIdentifier'], $futureParts)),
                );
            }
        }

        return $name;
    }

    /**
     * Generates an identifier from a list of column names obeying a certain string length.
     *
     * This is especially important for Oracle, since it does not allow identifiers larger than 30 chars,
     * however building idents automatically for foreign keys, composite keys or such can easily create
     * very long names.
     *
     * @param array<int, string> $columnNames
     */
    protected function _generateIdentifierName(array $columnNames, string $prefix = '', int $maxSize = 30): string
    {
        $hash = implode('', array_map(static function ($column): string {
            return dechex(crc32($column));
        }, $columnNames));

        return strtoupper(substr($prefix . '_' . $hash, 0, $maxSize));
    }
}
