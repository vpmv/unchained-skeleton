<?php

namespace App\System\Application;

use App\System\Application\Database\ApplicationReference;
use App\System\Application\Database\Column;
use App\System\Application\Database\Junction;
use App\System\Application\Database\JunctionList;
use App\System\Application\Database\ValueInterface;
use App\System\Application\Module\ApplicationModuleInterface;
use App\System\Application\Module\FormModule;
use App\System\Application\Translation\TranslatableChoice;
use App\System\Configuration\ApplicationConfig;
use App\System\Configuration\ConfigStore;
use App\System\Constructs\Translatable;

class Field
{
    public const VALUE_DEFAULT = 0;
    public const VALUE_PUBLIC  = 1;
    public const VALUE_LOCAL   = 2;

    public const VALUE_UNSET = '-';

    public const CONSTRAINT_UNIQUE = 'unique';

    private $id;
    private $config       = [];
    private $moduleConfig = [];
    private $schema       = [];
    private $visibility   = [];
    private $extra        = [
        'ignored'    => false, // fixme: change name => not visible in form
        'constraint' => false,
        'pointer'    => [],
    ];

    private $data = [];

    public function __construct(string $appId, string $identifier, array $config, ConfigStore $configStore, ?ApplicationConfig $context = null)
    {
        $this->appId = $appId; // fixme

        $this->id = $identifier;

        $this->setConfiguration($config);
        $this->setSchema($config);
        $this->setSource($config, $configStore, $context);
        $this->setModuleConfiguration($config);
        $this->setExtra($config);
        $this->setVisibility($config);
    }

    public function getId()
    {
        return $this->id;
    }

    public function isSlug()
    {
        return $this->id == '_slug';
    }

    public function isRequired(): bool
    {
        return $this->moduleConfig['form']['required'];
    }

    public function getLabel(string $type = 'default'): ?string
    {
        return $this->moduleConfig['label'][$type] ?? null;
    }

    public function getDisplayType(): string
    {
        return $this->config['type'];
    }

    public function getFormType(bool $getTypeNamespace = false): string
    {
        $type = $this->config['form_type'];
        if ($getTypeNamespace) {
            $type = '\\Symfony\\Component\\Form\\Extension\\Core\\Type\\' . ucfirst($type) . 'Type';
        }

        return $type;
    }

    public function getDefaultValue()
    {
        return $this->config['default'];
    }

    public function getData(?string $key = null, $default = null)
    {
        if ($key) {
            return $this->data[$key] ?? $default;
        }

        return $this->data;
    }

    /**
     * @param array|string $key
     * @param null         $value
     */
    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->data = array_replace($this->data, $key);

            return;
        }
        $this->data[$key] = $value;
    }

    public function getOutput(ApplicationModuleInterface $module): array
    {
        return [
            'visible'     => $this->isVisible($module),
            'value'       => $this->getData('value'),
            'raw'         => $this->getData('raw'),
            'link'        => $this->getData('url'),
            'title'       => $this->getData('title'),
            'reference'   => $this->getData('reference'),
            'transformed' => $this->getData('transformed', false),
            'field'       => [
                'type'        => $this->getDisplayType(),
                'form_type'   => $this->getFormType(),
                'column'      => $this->getId(),
                'default'     => $this->getDefaultValue(),
                'labels'      => [
                    'default'  => $this->getLabel(),
                    'enabled'  => $this->getLabel('enabled'),
                    'disabled' => $this->getLabel('disabled'),
                ],
                'source_id'   => $this->getSourceIdentifier(),
                'module'      => $this->getModuleConfig($module),
                'constraints' => $this->getConstraint(),
                //'source'    => $field->getSourceIdentifier() ? $this->getSource($field->getSourceIdentifier()) : null,
            ],
        ];
    }

    public function getModuleConfig(ApplicationModuleInterface $module): array
    {
        $config = $this->moduleConfig[$module->getName()] ?? [];
        if ($module instanceof FormModule) {
            $config += ['label' => $this->getLabel()];
        }

        return $config;
    }

    public function getSchema(bool $force = false): array
    {
        if ($force) {
            return $this->schema;
        }

        return $this->extra['ignored'] && $this->id != '_slug' ? [] : $this->schema;
    }

    public function getSourceIdentifier(): ?string
    {
        return $this->config['source']['id'] ?? null;
    }

    public function getSourceFields(): array
    {
        return $this->config['source']['visible'] ?? [];
    }

    public function hasConstraint(?string $type = null): bool
    {
        $hasConstraint = !empty($this->moduleConfig['constraint']);
        if (!$hasConstraint) {
            return false;
        } elseif (!$type) {
            return $hasConstraint;
        }

        return $this->moduleConfig['constraint'] == $type;
    }

    public function getConstraint()
    {
        return $this->extra['constraint'];
    }

    public function getChoiceOptions(): array
    {
        return $this->config['options'] ?? [];
    }

    public function isMultipleChoice(): bool
    {
        return $this->getFormType() == 'choice' && ($this->moduleConfig['form']['multiple'] ?? false);
    }

    public function getPointer(): ?array
    {
        if ($this->config['pointer']) {
            return $this->extra['pointer'];
        }

        return null;
    }

    /**
     * @param array $rowData
     *
     */
    public function setValue(Application $application, ValueInterface $value, ?ApplicationReference $reference = null): void
    {
        $this->setData([
            'value'       => null,
            'raw'         => $value ?? null,
            'url'         => null,
            'transformed' => false,
            'reference'   => null,
        ]);

        if ($value instanceof Column) {
            $this->setData('value', $value->getValue());
        } elseif ($value instanceof Junction) {
            $this->setData('value', $value->getValue());
            $this->setData('url', $application->getPublicUri($value->getApplication(), true, ['slug' => $value->getSlug()]));
            $this->setData('title', $value->getExposed());
        } elseif ($value instanceof JunctionList) {
            $values = $links = [];
            foreach ($value->getJunctions() as $junction) {
                $values[] = $junction->getValue();
                $links[]  = $application->getPublicUri($junction->getApplication(), true, ['slug' => $junction->getSlug()]);
            }
            $this->setData('value', $values);
            $this->setData('url', $links);
        }

        // fixme: pretty url
        if ($reference) {
            $path              = $application->getPublicUri($reference->getApplicationAlias(), true);
            $path['reference'] = $reference->getValue();
            $this->setData('reference', $path);
        }

        if ($this->config['pointer']) {
            $pointerType = $this->extra['pointer']['type'];

            $context = [];
            foreach ($this->extra['pointer']['fields'] as $pointerFieldId) {
                $context[$pointerFieldId] = $application->getRepository()->getColumn($pointerFieldId)->getValue();
            }

            if ($pointerType == 'external') {
                $value = $application->resolveExtension($this->id, $context);
                if ($value instanceof Translatable) {
                    $value = $application->translate($value->getMessage(), $value->getArguments());
                }
                $this->setData('value', $value);
            } elseif ($pointerType != 'slug') { // fixme
                $this->setData('value', $context);
            }
        }

        $this->convertValue($application);
    }

    public function convertValue(Application $application, $mode = self::VALUE_DEFAULT)
    {
        $currentModule = $application->getCurrentModule();

        $publicHtmlPath = $application->getDirectory(ConfigStore::DIR_PUBLIC);
        $filesPath      = $application->getDirectory(ConfigStore::DIR_FILES);
        $imagesPath     = $application->getDirectory(ConfigStore::DIR_IMAGES);

        $value = $this->getData('value');
        if ($value === null || $value === self::VALUE_UNSET) {
            $this->setData('value', null);

            return;
        }

        if ($this->getSourceIdentifier()) {
            $filesPath  = $application->getDirectory(ConfigStore::DIR_FILES, $this->getSourceIdentifier());
            $imagesPath = $application->getDirectory(ConfigStore::DIR_IMAGES, $this->getSourceIdentifier());
        }
        switch ($this->getDisplayType()) {
            case 'boolean':
                $value = intval($value);
                break;
            case 'file':
                $this->setData('view', $filesPath . '/' . $value);
                $value = ($currentModule instanceof FormModule ? $publicHtmlPath . $filesPath : $filesPath) . '/' . $value;
                break;
            case 'image':
                $this->setData('view', $imagesPath . '/' . $value);
                $value = ($currentModule instanceof FormModule ? $publicHtmlPath . $imagesPath : $imagesPath) . '/' . $value;
                break;
            case 'choice':
                if ($this->getSourceIdentifier() || $value === null || $value === []) {
                    return; // value is set by source
                } elseif (!is_array($value) && !is_null($value)) {
                    $value = (array)$value;
                }

                $choiceOptions  = $this->getChoiceOptions();
                $multipleChoice = $this->moduleConfig['form']['multiple'] ?? false;
                if (in_array($currentModule->getName(), [
                        'dashboard',
                        'detail',
                    ]) && $choiceOptions) {
                    $realValue = [];
                    foreach ($value as $idx) {
                        $translatable = $choiceOptions[$idx];
                        if (!$translatable instanceof TranslatableChoice) {
                            $translatable = new TranslatableChoice($this, $translatable);
                        }
                        $realValue[] = $application->translate($translatable->getMessage());
                    }
                    $value = implode(', ', $realValue);
                } else { // fixme: get string value for form
                    if (!$multipleChoice) {
                        $value = $value[0];
                    }
                }
                break;
            case 'date':
            case 'datetime':
                $value = new \DateTime($value);
                if (!$currentModule instanceof FormModule && ($transformer = $this->getTransformer('date'))) {
                    $this->setData('transformed', true);
                    $value = $application->timeElapsedString($value, $transformer['math_round'], $transformer['suffix'], $transformer['math_round_to']);
                }
                break;
            case 'time':
                $value = new \DateTime($value);
                break;
            case 'text':
            case 'varchar':
                if ($this->getSourceIdentifier() && is_array($this->getData('url'))) {
                    return; // maintain source values
                }
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                if (!$currentModule instanceof FormModule && ($transformer = $this->getTransformer('text'))) {
                    $this->setData('transformed', true);
                    $value .= ' ' . $transformer['suffix'];
                }
                break;
            case 'number':
                if (!$currentModule instanceof FormModule && ($transformer = $this->getTransformer())) {
                    $this->setData('transformed', true);

                    if ($transformer['math_round'] && strlen(intval($value)) != strlen($value)) {
                        $value = call_user_func_array($transformer['math_round'], [
                            $value,
                            $transformer['math_round_precision'],
                        ]);
                    }
                    settype($value, $transformer['scalar']);
                    if ($transformer['suffix']) {
                        $value .= ' ' . $transformer['suffix'];
                    }
                }
                break;
        }
        $this->setData('value', $value);
    }

    private function getTransformer(?string $fieldType = null)
    {
        $transformer = $this->extra['transformers'][$fieldType ?? $this->getDisplayType()] ?? [];
        if (isset($transformer['transform']) && $transformer['transform'] === false) {
            return null;
        }

        return $transformer;
    }

    public function isVisible(?ApplicationModuleInterface $module): bool
    {
        if (!$module) {
            return $this->visibility['public'];
        }

        return $this->visibility[$module->getName()];
    }

    /**
     * Temporary method setting visibility for module
     *
     * @param bool $visible
     *
     * @deprecated To be factored out
     */
    public function setModuleVisibility(ApplicationModuleInterface $module, bool $isAuthenticated = false)
    {
        if ($this->visibility[$module->getName()] && !$this->visibility['public']) {
            $this->visibility[$module->getName()] = $isAuthenticated;
        }
    }

    private function setConfiguration(array $config)
    {
        $this->config = [
            'type'      => $config['type'] ?? (!empty($config['source']) ? 'choice' : 'text'), // see setSources(); modified by exposed source column
            'form_type' => $config['type'] ?? (!empty($config['source']) ? 'choice' : 'text'),
            'label'     => Property::displayLabel($config['label'] ?? $this->id),
            'default'   => $config['default'] ?? null,
            'source'    => $config['source'] ?? null,
            'pointer'   => !empty($config['pointer']),
        ];

        $formTypes = [
            'boolean'  => 'checkbox',
            'datetime' => 'dateTime',
            'image'    => 'file',
            'rating'   => 'choice', // fixme: range slider / stars
            'textbox'  => 'textarea',
            'url'      => 'text',
        ];
        if (isset($formTypes[$this->config['type']])) {
            $this->config['form_type'] = $formTypes[$this->config['type']];
        }

        switch ($this->config['type']) {
            case 'rating': // fixme
            case 'choice':
                $this->config['options'] = $config['options'] ?? [];
                foreach ($this->config['options'] as &$value) {
                    $value = new TranslatableChoice($this, $value);
                }

                if (is_array($this->config['default'])) {
                    $__d                     = $this->config['default'];
                    $this->config['default'] = [];
                    foreach ($__d as $dk) {
                        $this->config['default'][$dk] = $config['options'][$dk];
                    }
                } elseif ($this->config['default'] !== null) {
                    $this->config['default'] = [$config['default'] => $config['options'][$config['default']]] ?? null;
                }
                break;

        }
    }

    private function setSchema(array $config)
    {
        $schemaTypes = [
            'text'     => 'varchar',
            'image'    => 'varchar',
            'file'     => 'varchar',
            'url'      => 'varchar',
            'tags'     => 'text',
            'textbox'  => 'text',
            'date'     => 'date',
            'datetime' => 'datetime',
            'time'     => 'time',
            'choice'   => 'int',
            'number'   => 'int',
            'boolean'  => 'tinyint',
            'checkbox' => 'tinyint',
            'rating'   => 'int',
        ];

        $this->schema = [
            'length'    => $config['length'] ?? null,
            'type'      => $schemaTypes[$config['type'] ?? 'text'],
            'type_meta' => null,
            'nullable'  => !filter_var($config['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'default'   => $config['default'] ?? ($config['required'] ?? false) ? '' : null,
            'options'   => [],
            'column'    => $this->id,
        ];

        switch ($this->config['type']) {
            case 'image':
            case 'file':
            case 'text':
            case 'url':
                $this->schema['length'] = $this->schema['length'] ?: 255;
                break;
            case 'choice':
                $this->schema['type']   = 'int';
                $this->schema['length'] = 11;
                if ($config['multiple'] ?? false) {
                    $this->schema['type']      = 'varchar';
                    $this->schema['type_meta'] = 'array';
                    $this->schema['length']    = 255;
                }
                break;
            case 'boolean':
            case 'checkbox':
                $this->schema['type']   = 'tinyint';
                $this->schema['length'] = 1;

                break;
            case 'number':
            case 'rating':
            case 'range':
                $this->schema['type']   = 'int';
                $this->schema['length'] = strlen($config['max'] ?? 11);
                break;
            case 'float':
                $this->schema['type'] = 'float';
                break;
        }
    }

    private function setSource(array $config, ConfigStore $configStore, ?ApplicationConfig $context = null)
    {
        if (!empty($config['source'])) {
            $sourceIdentifier = $config['source'];
            $visibleFields    = [];

            if (is_array($sourceIdentifier)) {
                $sourceIdentifier = $sourceIdentifier['source'];
                $visibleFields    = $sourceIdentifier['fields'] ?? [];
            } else {
                if (strpos($sourceIdentifier, '.')) {
                    [$sourceIdentifier, $visibleFields] = explode('.', $sourceIdentifier);
                }
            }
            $contextSourceConfig = $context->getSource($sourceIdentifier);
            $foreignColumn       = $contextSourceConfig['foreign_column'];

            $this->config['source'] = [
                'id'      => $sourceIdentifier,
                'visible' => (array)$visibleFields,
            ];
            // reference field with source to column
            $this->config['references'][$this->id] = [
                'context' => 'schema',
                'value'   => $foreignColumn,
            ];
            // reverse lookup
            $this->config['references'][$foreignColumn] = [
                'context' => 'column_source',
                'value'   => $this->id,
            ];
            $this->schema['column']                     = $foreignColumn;

            if ($contextSourceConfig['function'] || $contextSourceConfig['join_source']) {
                $this->extra['ignored'] = true;

                return; // stop reconfiguration
            }

            // convert display type
            if ($visibleFields) {
                $sourceAppId     = $contextSourceConfig['application'];
                $sourceAppConfig = $configStore->readApplicationConfig($sourceAppId);

                if (is_string($visibleFields) && !empty($sourceAppConfig['fields'][$visibleFields])) {
                    $sourceField = $sourceAppConfig['fields'][$visibleFields];
                } elseif (is_array($visibleFields)) {
                    if ($visibleFields) {
                        if ($sourceAppConfig['meta']['exposes']) {
                            $sourceField = (array)$sourceAppConfig['meta']['exposes'];
                        } else {
                            $sourceField = (array)array_filter($sourceAppConfig['fields'], function ($field) {
                                return filter_var($field['public'] ?? true, FILTER_VALIDATE_BOOLEAN);
                            })[0];
                        }
                        //} else {
                        //    $sourceField = (array)array_filter($visibleFields, function ($field) use ($sourceAppConfig) {
                        //        return isset($sourceAppConfig['fields'][$field]) && filter_var($sourceAppConfig['fields'][$field]['public'] ?? true, FILTER_VALIDATE_BOOLEAN);
                        //    })[0];
                        //}
                    }
                    if (count($sourceField) > 1) {
                        return; // combined fields are always textual
                    }
                    $sourceField = $sourceField[0];
                }
                $this->config['type'] = $sourceField['type']; // source DisplayType overrules our DisplayType, whilst maintaining the FormType
            }
        }
    }

    private function setModuleConfiguration(array $config)
    {
        $this->moduleConfig = [
            'sortable' => filter_var($config['sortable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'label'    => [
                'default' => $this->config['label'],
            ],
            'form'     => [
                'required' => filter_var($config['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'attr'     => [],
            ],
        ];

        if ($this->extra['ignored']) {
            return;
        }

        switch ($this->config['form_type']) {
            case 'date':
            case 'datetime':
                $this->moduleConfig['form']['format'] = $config['format'] ?? 'yyyyMMdd';
                if ($config['type'] == 'datetime') {
                    $this->moduleConfig['form']['format'] = $config['format'] ?? 'yyyyMMddHHii';
                }
                $yearsRange = [
                    date('Y') - 10,
                    date('Y') + 10,
                ];

                if (isset($config['year_min'])) {
                    $yearsRange[0] = strlen($config['year_min']) <= 3 ? date('Y') - $config['year_min'] : $config['year_min'];
                }
                if (isset($config['year_max'])) {
                    $yearsRange[1] = strlen($config['year_max']) <= 3 ? date('Y') + $config['year_max'] : $config['year_max'];
                }
                $this->moduleConfig['form']['years'] = range(...$yearsRange);
                break;
            case 'image':
            case 'file':
            case 'text':
                $this->moduleConfig['form']['attr']['maxlength'] = $config['maxlength'] ?? 255;
                break;
            case 'rating': // fixme
            case 'choice':
                    $this->moduleConfig['form']['required'] = filter_var($config['required'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $this->moduleConfig['form']['multiple'] = !empty($config['multiple']);
                    $this->moduleConfig['form']['expanded'] = !empty($config['expanded']);
                    $this->moduleConfig['form']['choices']  = $this->config['options'] ?? [];
                    $this->moduleConfig['unique']           = filter_var($config['unique'] ?? false, FILTER_VALIDATE_BOOLEAN); // fixme: different position
                break;
            case 'boolean':
            case 'checkbox':
                $this->moduleConfig['label']['enabled']  = $config['label_enabled'] ?? Property::displayLabel($this->moduleConfig['label']['default'] . '.enabled');
                $this->moduleConfig['label']['disabled'] = $config['label_disabled'] ?? Property::displayLabel($this->moduleConfig['label']['default'] . '.disabled');
                break;
            case 'number':
                $this->moduleConfig['form']['attr']['min'] = $config['min'] ?? -PHP_INT_MAX;
                $this->moduleConfig['form']['attr']['max'] = $config['max'] ?? PHP_INT_MAX;
                break;
            case 'float':
                $this->moduleConfig['form']['attr']['min'] = $config['min'] ?? -PHP_INT_MAX;
                $this->moduleConfig['form']['attr']['max'] = $config['max'] ?? PHP_INT_MAX;
                break;
        }
    }

    private function setExtra(array $config)
    {
        $this->extra['ignored'] = $this->extra['ignored'] || ($config['ignored'] ?? false);

        if ($config['pointer'] ?? false) {
            $this->extra['ignored'] = true;
            $this->extra['pointer'] = [
                'fields' => [],
                'type'   => $config['pointer']['type'] ?? false,
            ];

            if (!empty($config['pointer']['fields'])) {
                $this->extra['pointer']['fields'] = (array)$config['pointer']['fields'];
            } elseif (is_string($config['pointer']) || is_array($config['pointer'])) {
                $this->extra['pointer']['fields'] = (array)$config['pointer'];
            }
        }

        if ($this->moduleConfig['unique'] ?? null) {
            $this->extra['constraint'] = 'unique';
        }

        $this->extra['transformers'] = [
            // fixme a field is only one type
            'date'   => [
                'transform'     => !empty($config['_transform']['date']),
                'suffix'        => $config['_transform']['date']['suffix'] ?? null,
                'math_round'    => $config['_transform']['date']['round'] ?? 'floor',
                'math_round_to' => $config['_transform']['date']['round_to'] ?? 'auto',
            ],
            'number' => [
                'scalar'               => $config['_transform']['number']['scalar'] ?? 'string',
                'math_round'           => $config['_transform']['number']['round'] ?? false,
                'math_round_precision' => $config['_transform']['number']['round_precision'] ?? 2,
                'suffix'               => $config['_transform']['number']['suffix'] ?? null,
            ],
            'text'   => [
                'suffix' => $config['_transform']['text']['suffix'] ?? null,
            ],
        ];
    }

    private function setVisibility(array $config)
    {
        $this->visibility = [
            'public'    => filter_var($config['public'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'dashboard' => !empty($config['dashboard'] ?? true),
            'detail'    => filter_var($config['detail'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'form'      => !$this->extra['ignored'],
        ];

        $classes                                  = [
            'all'       => 'all',
            'visible'   => 'all',
            'invisible' => 'never',
            'detail'    => 'none',
            'large'     => 'tablet-l desktop',
            'small'     => 'mobile-p mobile-l tablet-p',
            'desktop'   => 'desktop',
            'portrait'  => 'mobile-p tablet-p',
            'landscape' => 'mobile-l tablet-l',
            'mobile'    => 'mobile-p',
            'tablet'    => 'tablet-l tablet-p',
        ];
        $this->moduleConfig['dashboard']['class'] = $classes[$config['dashboard']['visibility'] ?? 'all'] ?? 'all';
    }
}