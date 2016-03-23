<?php

namespace PhpIntegrator;

use ArrayAccess;
use ArrayObject;
use Traversable;

/**
 * Adapts and resolves data from the index as needed to receive an appropriate output data format.
 */
class IndexDataAdapter
{
    /**
     * The storage to use for accessing index data.
     *
     * @var IndexDataAdapter\ProviderInterface
     */
    protected $storage;

    /**
     * Constructor.
     *
     * @param IndexDataAdapter\ProviderInterface $storage
     */
    public function __construct(IndexDataAdapter\ProviderInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Retrieves information about the specified structural element.
     *
     * @param int $id
     *
     * @return array
     */
    public function getStructuralElementInfo($id)
    {
        return $this->resolveStructuralElement(
            $this->storage->getStructuralElementRawInfo($id),
            $this->storage->getStructuralElementRawParents($id),
            $this->storage->getStructuralElementRawChildren($id),
            $this->storage->getStructuralElementRawInterfaces($id),
            $this->storage->getStructuralElementRawImplementors($id),
            $this->storage->getStructuralElementRawTraits($id),
            $this->storage->getStructuralElementRawTraitUsers($id),
            $this->storage->getStructuralElementRawConstants($id),
            $this->storage->getStructuralElementRawProperties($id),
            $this->storage->getStructuralElementRawMethods($id)
        );
    }

    /**
     * Resolves structural element information from the specified raw data.
     *
     * @param array|ArrayAccess $element
     * @param array|Traversable $parents
     * @param array|Traversable $children
     * @param array|Traversable $interfaces
     * @param array|Traversable $implementors
     * @param array|Traversable $traits
     * @param array|Traversable $traitUsers
     * @param array|Traversable $constants
     * @param array|Traversable $properties
     * @param array|Traversable $methods
     *
     * @return array
     */
    public function resolveStructuralElement(
        $element,
        $parents,
        $children,
        $interfaces,
        $implementors,
        $traits,
        $traitUsers,
        $constants,
        $properties,
        $methods
    ) {
        $result = new ArrayObject([
            'name'               => $element['fqsen'],
            'startLine'          => (int) $element['start_line'],
            'endLine'            => (int) $element['end_line'],
            'shortName'          => $element['name'],
            'filename'           => $element['path'],
            'type'               => $element['type_name'],
            'isAbstract'         => !!$element['is_abstract'],
            'isBuiltin'          => !!$element['is_builtin'],
            'isDeprecated'       => !!$element['is_deprecated'],

            'descriptions'       => [
                'short' => $element['short_description'],
                'long'  => $element['long_description']
            ],

            'parents'            => [],
            'interfaces'         => [],
            'traits'             => [],

            'directParents'      => [],
            'directInterfaces'   => [],
            'directTraits'       => [],
            'directChildren'     => [],
            'directImplementors' => [],
            'directTraitUsers'   => [],

            'constants'          => [],
            'properties'         => [],
            'methods'            => []
        ]);

        $this->parseChildrenData($result, $children);
        $this->parseImplementorsData($result, $implementors);
        $this->parseTraitUsersData($result, $traitUsers);

        $this->parseParentData($result, $parents);
        $this->parseInterfaceData($result, $interfaces);
        $this->parseTraitData($result, $traits, $element);

        $this->parseConstantData($result, $constants, $element);
        $this->parsePropertyData($result, $properties, $element);
        $this->parseMethodData($result, $methods, $element);

        $this->resolveReturnTypes($result, $element['fqsen']);

        return $result->getArrayCopy();
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $constants
     * @param array|ArrayAccess $element
     */
    protected function parseConstantData(ArrayObject $result, $constants, $element)
    {
        foreach ($constants as $rawConstantData) {
            $result['constants'][$rawConstantData['name']] = array_merge($this->getConstantInfo($rawConstantData), [
                'declaringClass' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                ],

                'declaringStructure' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                    'startLineMember' => (int) $rawConstantData['start_line'],
                    'endLineMember'   => (int) $rawConstantData['end_line']
                ]
            ]);
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $properties
     * @param array|ArrayAccess $element
     */
    protected function parsePropertyData(ArrayObject $result, $properties, $element)
    {
        foreach ($properties as $rawPropertyData) {
            $inheritedData = [];
            $existingProperty = null;
            $overriddenPropertyData = null;

            $property = $this->getPropertyInfo($rawPropertyData);

            if (isset($result['properties'][$property['name']])) {
                $existingProperty = $result['properties'][$property['name']];

                $overriddenPropertyData = [
                    'declaringClass'     => $existingProperty['declaringClass'],
                    'declaringStructure' => $existingProperty['declaringStructure'],
                    'startLine'          => (int) $existingProperty['startLine'],
                    'endLine'            => (int) $existingProperty['endLine']
                ];

                if ($this->isInheritingDocumentation($property)) {
                    $inheritedData = $this->extractInheritedPropertyInfo($existingProperty);
                }
            }

            $resultingProperty = array_merge($property, $inheritedData, [
                'override'       => $overriddenPropertyData,

                'declaringClass' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                ],

                'declaringStructure' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                    'startLineMember' => (int) $rawPropertyData['start_line'],
                    'endLineMember'   => (int) $rawPropertyData['end_line']
                ]
            ]);

            if ($resultingProperty['return']['type'] === 'self') {
                $resultingProperty['return']['resolvedType'] = $element['fqsen'];
            }

            if ($existingProperty) {
                $resultingProperty['descriptions']['long'] = $this->resolveInheritDoc(
                    $resultingProperty['descriptions']['long'],
                    $existingProperty['descriptions']['long']
                );
            }

            $result['properties'][$property['name']] = $resultingProperty;
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $methods
     * @param array|ArrayAccess $element
     */
    protected function parseMethodData(ArrayObject $result, $methods, $element)
    {
        foreach ($methods as $rawMethodData) {
            $inheritedData = [];
            $existingMethod = null;
            $overriddenMethodData = null;
            $implementedMethodData = null;

            $method = $this->getMethodInfo($rawMethodData);

            if (isset($result['methods'][$method['name']])) {
                $existingMethod = $result['methods'][$method['name']];

                if ($existingMethod['declaringStructure']['type'] === 'interface') {
                    $implementedMethodData = [
                        'declaringClass'     => $existingMethod['declaringClass'],
                        'declaringStructure' => $existingMethod['declaringStructure'],
                        'startLine'          => (int) $existingMethod['startLine'],
                        'endLine'            => (int) $existingMethod['endLine']
                    ];
                } else {
                    $overriddenMethodData = [
                        'declaringClass'     => $existingMethod['declaringClass'],
                        'declaringStructure' => $existingMethod['declaringStructure'],
                        'startLine'          => (int) $existingMethod['startLine'],
                        'endLine'            => (int) $existingMethod['endLine'],
                        'wasAbstract'        => (bool) $existingMethod['isAbstract']
                    ];
                }

                if ($this->isInheritingDocumentation($method)) {
                    $inheritedData = $this->extractInheritedMethodInfo($existingMethod);
                }
            }

            $resultingMethod = array_merge($method, $inheritedData, [
                'override'       => $overriddenMethodData,
                'implementation' => $implementedMethodData,

                'declaringClass' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                ],

                'declaringStructure' => [
                    'name'            => $element['fqsen'],
                    'filename'        => $element['path'],
                    'startLine'       => (int) $element['start_line'],
                    'endLine'         => (int) $element['end_line'],
                    'type'            => $element['type_name'],
                    'startLineMember' => (int) $rawMethodData['start_line'],
                    'endLineMember'   => (int) $rawMethodData['end_line']
                ]
            ]);

            if ($resultingMethod['return']['type'] === 'self') {
                $resultingMethod['return']['resolvedType'] = $element['fqsen'];
            }

            if ($existingMethod) {
                $resultingMethod['descriptions']['long'] = $this->resolveInheritDoc(
                    $resultingMethod['descriptions']['long'],
                    $existingMethod['descriptions']['long']
                );
            }

            $result['methods'][$method['name']] = $resultingMethod;
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $children
     */
    protected function parseChildrenData(ArrayObject $result, $children)
    {
        foreach ($children as $child) {
            $result['directChildren'][] = $child['fqsen'];
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $implementors
     */
    protected function parseImplementorsData(ArrayObject $result, $implementors)
    {
        foreach ($implementors as $implementor) {
            $result['directImplementors'][] = $implementor['fqsen'];
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $traitUsers
     */
    protected function parseTraitUsersData(ArrayObject $result, $traitUsers)
    {
        foreach ($traitUsers as $trait) {
            $result['directTraitUsers'][] = $trait['fqsen'];
        }
    }

    /**
     * Takes all members from base classes and attaches them to the result data.
     *
     * @param ArrayObject       $result
     * @param array|Traversable $parents One or more base classes to inherit from (interfaces can have multiple parents).
     */
    protected function parseParentData(ArrayObject $result, $parents)
    {
        foreach ($parents as $parent) {
            $parentInfo = $this->getStructuralElementInfo($parent['id']);

            if ($parentInfo) {
                if (!$result['descriptions']['short']) {
                    $result['descriptions']['short'] = $parentInfo['descriptions']['short'];
                }

                if (!$result['descriptions']['long']) {
                    $result['descriptions']['long'] = $parentInfo['descriptions']['long'];
                } else {
                    $result['descriptions']['long'] = $this->resolveInheritDoc(
                        $result['descriptions']['long'],
                        $parentInfo['descriptions']['long']
                    );
                }

                $result['constants']  = array_merge($result['constants'], $parentInfo['constants']);
                $result['properties'] = array_merge($result['properties'], $parentInfo['properties']);
                $result['methods']    = array_merge($result['methods'], $parentInfo['methods']);

                $result['traits']     = array_merge($result['traits'], $parentInfo['traits']);
                $result['interfaces'] = array_merge($result['interfaces'], $parentInfo['interfaces']);
                $result['parents']    = array_merge($result['parents'], [$parentInfo['name']], $parentInfo['parents']);

                $result['directParents'][] = $parentInfo['name'];
            }
        }
    }

    /**
     * Appends members from direct interfaces to the pool of members. These only supply additional members, but will
     * never overwrite any existing members as they have a lower priority than inherited members.
     *
     * @param ArrayObject       $result
     * @param array|Traversable $interfaces
     */
    protected function parseInterfaceData(ArrayObject $result, $interfaces)
    {
        foreach ($interfaces as $interface) {
            $interface = $this->getStructuralElementInfo($interface['id']);

            $result['interfaces'][] = $interface['name'];
            $result['directInterfaces'][] = $interface['name'];

            foreach ($interface['constants'] as $constant) {
                if (!isset($result['constants'][$constant['name']])) {
                    $result['constants'][$constant['name']] = $constant;
                }
            }

            foreach ($interface['methods'] as $method) {
                if (!isset($result['methods'][$method['name']])) {
                    $result['methods'][$method['name']] = $method;
                }
            }
        }
    }

    /**
     * @param ArrayObject       $result
     * @param array|Traversable $traits
     * @param array             $element
     *
     * @return array
     */
    protected function parseTraitData(ArrayObject $result, Traversable $traits, $element)
    {
        $traitAliases = $this->storage->getStructuralElementTraitAliasesAssoc($element['id']);
        $traitPrecedences = $this->storage->getStructuralElementTraitPrecedencesAssoc($element['id']);

        foreach ($traits as $trait) {
            $trait = $this->getStructuralElementInfo($trait['id']);

            $result['traits'][] = $trait['name'];
            $result['directTraits'][] = $trait['name'];

            foreach ($trait['properties'] as $property) {
                $inheritedData = [];
                $existingProperty = null;

                if (isset($result['properties'][$property['name']])) {
                    $existingProperty = $result['properties'][$property['name']];

                    if ($this->isInheritingDocumentation($property)) {
                        $inheritedData = $this->extractInheritedPropertyInfo($existingProperty);
                    }
                }

                $resultingProperty = array_merge($property, $inheritedData, [
                    'declaringClass' => [
                        'name'            => $element['fqsen'],
                        'filename'        => $element['path'],
                        'startLine'       => (int) $element['start_line'],
                        'endLine'         => (int) $element['end_line'],
                        'type'            => $element['type_name'],
                    ]
                ]);

                if ($existingProperty) {
                    $resultingProperty['descriptions']['long'] = $this->resolveInheritDoc(
                        $resultingProperty['descriptions']['long'],
                        $existingProperty['descriptions']['long']
                    );
                }

                $result['properties'][$property['name']] = $resultingProperty;
            }

            foreach ($trait['methods'] as $method) {
                if (isset($traitAliases[$method['name']])) {
                    $alias = $traitAliases[$method['name']];

                    if ($alias['trait_fqsen'] === null || $alias['trait_fqsen'] === $trait['name']) {
                        $method['name']        = $alias['alias'] ?: $method['name'];
                        $method['isPublic']    = ($alias['access_modifier'] === 'public');
                        $method['isProtected'] = ($alias['access_modifier'] === 'protected');
                        $method['isPrivate']   = ($alias['access_modifier'] === 'private');
                    }
                }

                $inheritedData = [];
                $existingMethod = null;

                if (isset($result['methods'][$method['name']])) {
                    $existingMethod = $result['methods'][$method['name']];

                    if ($existingMethod['declaringStructure']['type'] === 'trait') {
                        if (isset($traitPrecedences[$method['name']])) {
                            if ($traitPrecedences[$method['name']]['trait_fqsen'] !== $trait['name']) {
                                // The method is present in multiple used traits and precedences indicate that the one
                                // from this trait should not be imported.
                                continue;
                            }
                        }
                    }

                    if ($this->isInheritingDocumentation($method)) {
                        $inheritedData = $this->extractInheritedMethodInfo($existingMethod);
                    }
                }

                $resultingMethod = array_merge($method, $inheritedData, [
                    'declaringClass' => [
                        'name'            => $element['fqsen'],
                        'filename'        => $element['path'],
                        'startLine'       => (int) $element['start_line'],
                        'endLine'         => (int) $element['end_line'],
                        'type'            => $element['type_name'],
                    ]
                ]);

                if ($existingMethod) {
                    $resultingMethod['descriptions']['long'] = $this->resolveInheritDoc(
                        $resultingMethod['descriptions']['long'],
                        $existingMethod['descriptions']['long']
                    );
                }

                $result['methods'][$method['name']] = $resultingMethod;
            }
        }
    }

    /**
     * @param ArrayObject $result
     * @param string      $elementFqsen
     */
    protected function resolveReturnTypes(ArrayObject $result, $elementFqsen)
    {
        foreach ($result['methods'] as $name => &$method) {
            if ($method['return']['type'] === '$this' || $method['return']['type'] === 'static') {
                $method['return']['resolvedType'] = $elementFqsen;
            } elseif (!isset($method['return']['resolvedType'])) {
                $method['return']['resolvedType'] = $method['return']['type'];
            }
        }

        foreach ($result['properties'] as $name => &$property) {
            if ($property['return']['type'] === '$this' || $property['return']['type'] === 'static') {
                $property['return']['resolvedType'] = $elementFqsen;
            } elseif (!isset($property['return']['resolvedType'])) {
                $property['return']['resolvedType'] = $property['return']['type'];
            }
        }
    }

    /**
     * @param array $rawInfo
     *
     * @return array
     */
    public function getMethodInfo(array $rawInfo)
    {
        return array_merge($this->getFunctionInfo($rawInfo), [
            'isMagic'            => !!$rawInfo['is_magic'],
            'isPublic'           => ($rawInfo['access_modifier'] === 'public'),
            'isProtected'        => ($rawInfo['access_modifier'] === 'protected'),
            'isPrivate'          => ($rawInfo['access_modifier'] === 'private'),
            'isStatic'           => !!$rawInfo['is_static'],
            'isAbstract'         => !!$rawInfo['is_abstract'],

            'override'           => null,
            'implementation'     => null,

            'declaringClass'     => null,
            'declaringStructure' => null
        ]);
    }

    /**
     * @param array $rawInfo
     *
     * @return array
     */
    public function getFunctionInfo(array $rawInfo)
    {
        $rawParameters = unserialize($rawInfo['parameters_serialized']);

        $parameters = [];

        foreach ($rawParameters as $rawParameter) {
            $parameters[] = [
                'name'        => $rawParameter['name'],
                'type'        => $rawParameter['type'],
                'fullType'    => $rawParameter['full_type'],
                'description' => $rawParameter['description'],
                'isReference' => !!$rawParameter['is_reference'],
                'isVariadic'  => !!$rawParameter['is_variadic'],
                'isOptional'  => !!$rawParameter['is_optional']
            ];
        }

        $throws = unserialize($rawInfo['throws_serialized']);

        $throwsAssoc = [];

        foreach ($throws as $throws) {
            $throwsAssoc[$throws['type']] = $throws['description'];
        }

        return [
            'name'          => $rawInfo['name'],
            'isBuiltin'     => !!$rawInfo['is_builtin'],
            'startLine'     => (int) $rawInfo['start_line'],
            'endLine'       => (int) $rawInfo['end_line'],
            'filename'      => $rawInfo['path'],

            'parameters'    => $parameters,
            'throws'        => $throwsAssoc,
            'isDeprecated'  => !!$rawInfo['is_deprecated'],
            'hasDocblock'   => !!$rawInfo['has_docblock'],

            'descriptions'  => [
                'short' => $rawInfo['short_description'],
                'long'  => $rawInfo['long_description']
            ],

            'return'        => [
                'type'         => $rawInfo['return_type'],
                'resolvedType' => $rawInfo['full_return_type'],
                'description'  => $rawInfo['return_description']
            ]
        ];
    }

    /**
     * @param array $rawInfo
     *
     * @return array
     */
    public function getPropertyInfo(array $rawInfo)
    {
        return [
            'name'               => $rawInfo['name'],
            'startLine'          => (int) $rawInfo['start_line'],
            'endLine'            => (int) $rawInfo['end_line'],
            'isMagic'            => !!$rawInfo['is_magic'],
            'isPublic'           => ($rawInfo['access_modifier'] === 'public'),
            'isProtected'        => ($rawInfo['access_modifier'] === 'protected'),
            'isPrivate'          => ($rawInfo['access_modifier'] === 'private'),
            'isStatic'           => !!$rawInfo['is_static'],
            'isDeprecated'       => !!$rawInfo['is_deprecated'],
            'hasDocblock'        => !!$rawInfo['has_docblock'],

            'descriptions'  => [
                'short' => $rawInfo['short_description'],
                'long'  => $rawInfo['long_description']
            ],

            'return'        => [
                'type'         => $rawInfo['return_type'],
                'resolvedType' => $rawInfo['full_return_type'],
                'description'  => $rawInfo['return_description']
            ],

            'override'           => null,
            'declaringClass'     => null,
            'declaringStructure' => null
        ];
    }

    /**
     * @param array $rawInfo
     *
     * @return array
     */
    public function getConstantInfo(array $rawInfo)
    {
        return [
            'name'         => $rawInfo['name'],
            'isBuiltin'    => !!$rawInfo['is_builtin'],
            'startLine'    => (int) $rawInfo['start_line'],
            'endLine'      => (int) $rawInfo['end_line'],
            'filename'     => $rawInfo['path'],

            'isPublic'     => true,
            'isProtected'  => false,
            'isPrivate'    => false,
            'isStatic'     => true,
            'isDeprecated' => !!$rawInfo['is_deprecated'],
            'hasDocblock'  => !!$rawInfo['has_docblock'],

            'descriptions'  => [
                'short' => $rawInfo['short_description'],
                'long'  => $rawInfo['long_description']
            ],

            'return'        => [
                'type'         => $rawInfo['return_type'],
                'resolvedType' => $rawInfo['full_return_type'],
                'description'  => $rawInfo['return_description']
            ],
        ];
    }

    /**
     * Returns a boolean indicating whether the specified item will inherit documentation from a parent item (if
     * present).
     *
     * @param array $processedData
     *
     * @return bool
     */
    protected function isInheritingDocumentation(array $processedData)
    {
        $specialTags = [
            // Ticket #86 - Inherit the entire parent docblock if the docblock contains nothing but these tags.
            // According to draft PSR-5 and phpDocumentor's implementation, these are incorrect. However, some large
            // frameworks (such as Symfony 2) use these and it thus makes life easier for many  developers.
            '{@inheritdoc}', '{@inheritDoc}',

            // This tag (without curly braces) is, according to draft PSR-5, a valid way to indicate an entire docblock
            // should be inherited and to implicitly indicate that documentation was not forgotten.
            '@inheritDoc'
        ];

        return !$processedData['hasDocblock'] || in_array($processedData['descriptions']['short'], $specialTags);
    }

    /**
     * Resolves the inheritDoc tag for the specified description.
     *
     * Note that according to phpDocumentor this only works for the long description (not the so-called 'summary' or
     * short description).
     *
     * @param string $description
     * @param string $parentDescription
     *
     * @return string
     */
    protected function resolveInheritDoc($description, $parentDescription)
    {
        return str_replace(DocParser::INHERITDOC, $parentDescription, $description);
    }

    /**
     * Extracts data from the specified (processed, i.e. already in the output format) property that is inheritable.
     *
     * @param array $processedData
     *
     * @return array
     */
    protected function extractInheritedPropertyInfo(array $processedData)
    {
        $info = [];

        $inheritedKeys = [
            'isDeprecated',
            'descriptions',
            'return'
        ];

        foreach ($processedData as $key => $value) {
            if (in_array($key, $inheritedKeys)) {
                $info[$key] = $value;
            }
        }

        return $info;
    }

    /**
     * Extracts data from the specified (processed, i.e. already in the output format) method that is inheritable.
     *
     * @param array $processedData
     *
     * @return array
     */
    protected function extractInheritedMethodInfo(array $processedData)
    {
        $info = [];

        $inheritedKeys = [
            'isDeprecated',
            'descriptions',
            'return',
            'parameters',
            'throws'
        ];

        foreach ($processedData as $key => $value) {
            if (in_array($key, $inheritedKeys)) {
                $info[$key] = $value;
            }
        }

        return $info;
    }
}
