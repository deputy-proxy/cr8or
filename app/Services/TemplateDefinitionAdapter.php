<?php

namespace App\Services;

use RuntimeException;

final class TemplateDefinitionAdapter
{
    private const SUPPORTED_SCHEMA_VERSIONS = ['2'];

    /**
     * Normalize the current cr8or-media v2 JSON shape into CR8OR catalogue metadata.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function normalize(array $definition, string $path): array
    {
        if (isset($definition['catalog']) || isset($definition['metadata']) || isset($definition['key']) || isset($definition['template_key'])) {
            $metadata = is_array($definition['catalog'] ?? null)
                ? $definition['catalog']
                : (is_array($definition['metadata'] ?? null) ? $definition['metadata'] : $definition);
            $metadata['key'] = $metadata['key'] ?? $metadata['template_key'] ?? null;
            $metadata['output_format'] = $this->normalizeFormat((string) ($metadata['output_format'] ?? $metadata['format'] ?? ''));
            $metadata['schema_version'] = (string) ($metadata['schema_version'] ?? '');

            return $this->validateMetadata($metadata, $path);
        }

        // Current cr8or-media definitions use id/name/version/format, canvas,
        // sampleData, background, and elements. "reel" is the renderer's video format.
        if ((string) ($definition['version'] ?? '') !== '2') {
            throw new RuntimeException("Definition [{$path}] uses an unsupported cr8or-media schema version.");
        }
        $format = $this->normalizeFormat((string) ($definition['format'] ?? ''));
        $canvas = $definition['canvas'] ?? null;
        $background = $definition['background'] ?? null;
        $sampleData = $definition['sampleData'] ?? [];
        $elements = $definition['elements'] ?? null;
        if (! is_array($canvas)
            || ! is_numeric($canvas['width']) || $canvas['width'] <= 0
            || ! is_numeric($canvas['height']) || $canvas['height'] <= 0
            || ! is_numeric($canvas['fps']) || $canvas['fps'] <= 0
            || ! is_numeric($canvas['duration']) || $canvas['duration'] <= 0) {
            throw new RuntimeException("Definition [{$path}] must declare positive canvas width, height, fps, and duration.");
        }
        if (! is_array($background) || ! is_string($background['type'] ?? null) || trim($background['type']) === '') {
            throw new RuntimeException("Definition [{$path}] must declare a supported background object and type.");
        }
        if (! is_array($sampleData) || ! is_array($elements) || ! array_is_list($elements)) {
            throw new RuntimeException("Definition [{$path}] sampleData must be an object and elements must be an array.");
        }
        $elementIds = [];
        $elementTypes = [];
        foreach ($elements as $element) {
            if (! is_array($element) || ! is_string($element['id'] ?? null) || trim($element['id']) === '' || ! is_string($element['type'] ?? null) || trim($element['type']) === '') {
                throw new RuntimeException("Definition [{$path}] elements must declare non-empty id and type fields.");
            }
            if (isset($elementIds[$element['id']])) {
                throw new RuntimeException("Definition [{$path}] contains duplicate element id [{$element['id']}].");
            }
            $elementIds[$element['id']] = true;
            $elementTypes[] = $element['type'];
        }
        if (isset($background['props']) && ! is_array($background['props'])) {
            throw new RuntimeException("Definition [{$path}] background props must be an object.");
        }

        $fields = [];
        foreach ($sampleData as $field => $value) {
            if (! is_string($field) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/', $field)) {
                throw new RuntimeException("Definition [{$path}] has an invalid sampleData field name.");
            }
            $fields[$field] = ['type' => match (true) {
                is_string($value) => 'string',
                is_int($value) => 'integer',
                is_float($value) => 'number',
                is_bool($value) => 'boolean',
                is_array($value) => 'array',
                default => throw new RuntimeException("Definition [{$path}] has an unsupported sampleData value for [{$field}]."),
            }];
        }

        $elementTypes = array_values(array_unique($elementTypes));
        $backgroundType = $background['type'];

        return $this->validateMetadata([
            'key' => $definition['id'] ?? null,
            'name' => $definition['name'] ?? null,
            'description' => $definition['description'] ?? null,
            'purpose' => $definition['purpose'] ?? null,
            'project' => $definition['project'] ?? null,
            'output_format' => $format,
            'renderer_targets' => [$format === 'image' ? 'cr8or-image' : 'cr8or-video'],
            'schema_version' => (string) ($definition['version'] ?? ''),
            'input_contract' => [
                'required' => [],
                'optional' => array_keys($sampleData),
                'fields' => $fields,
                'additional_properties' => false,
            ],
            'media_contract' => [
                'element_types' => $elementTypes,
                'background_type' => $backgroundType,
                'accepts_media_references' => in_array('image', $elementTypes, true)
                    || in_array('video', $elementTypes, true)
                    || in_array($backgroundType, ['image', 'video'], true),
            ],
            'rendering_contract' => [
                'canvas' => [
                    'width' => $canvas['width'],
                    'height' => $canvas['height'],
                    'fps' => $canvas['fps'],
                    'duration' => $canvas['duration'],
                ],
                'element_count' => count($elements),
                'background_type' => $backgroundType,
                'format' => $definition['format'] ?? null,
            ],
        ], $path);
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function validateMetadata(array $metadata, string $path): array
    {
        $key = trim((string) ($metadata['key'] ?? ''));
        $name = trim((string) ($metadata['name'] ?? ''));
        $format = $this->normalizeFormat((string) ($metadata['output_format'] ?? ''));
        $schemaVersion = trim((string) ($metadata['schema_version'] ?? ''));

        if ($key === '' || $name === '' || ! in_array($format, ['image', 'video'], true) || $schemaVersion === '') {
            throw new RuntimeException("Definition [{$path}] must declare a stable id/key, name, supported format, and schema version.");
        }
        if (! in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw new RuntimeException("Definition [{$path}] uses unsupported template schema version [{$schemaVersion}].");
        }
        if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $key)) {
            throw new RuntimeException("Definition [{$path}] has an invalid stable template key.");
        }
        foreach (['input_contract', 'media_contract', 'rendering_contract'] as $contract) {
            if (isset($metadata[$contract]) && ! is_array($metadata[$contract])) {
                throw new RuntimeException("Definition [{$path}] field [{$contract}] must be an object.");
            }
        }
        $this->validateInputContract($metadata['input_contract'] ?? [], $path);

        $metadata['key'] = $key;
        $metadata['name'] = $name;
        $metadata['output_format'] = $format;
        $metadata['schema_version'] = $schemaVersion;
        $metadata['renderer_targets'] = is_array($metadata['renderer_targets'] ?? null)
            ? $metadata['renderer_targets']
            : [$format === 'image' ? 'cr8or-image' : 'cr8or-video'];
        $metadata['input_contract'] = is_array($metadata['input_contract'] ?? null) ? $metadata['input_contract'] : [];
        $metadata['media_contract'] = is_array($metadata['media_contract'] ?? null) ? $metadata['media_contract'] : [];
        $metadata['rendering_contract'] = is_array($metadata['rendering_contract'] ?? null) ? $metadata['rendering_contract'] : [];

        return $metadata;
    }

    /** @param array<string, mixed> $contract */
    private function validateInputContract(array $contract, string $path): void
    {
        if ($contract === []) {
            return;
        }

        foreach (['required', 'optional'] as $list) {
            $values = $contract[$list] ?? [];
            if (! is_array($values) || array_filter($values, static fn ($value): bool => ! is_string($value) || trim($value) === '') !== []) {
                throw new RuntimeException("Definition [{$path}] input contract [{$list}] must contain non-empty field names.");
            }
            if (count($values) !== count(array_unique($values))) {
                throw new RuntimeException("Definition [{$path}] input contract [{$list}] contains duplicate fields.");
            }
        }

        $required = $contract['required'] ?? [];
        $optional = $contract['optional'] ?? [];
        if (array_intersect($required, $optional) !== []) {
            throw new RuntimeException("Definition [{$path}] input fields cannot be both required and optional.");
        }
        if (isset($contract['additional_properties']) && ! is_bool($contract['additional_properties'])) {
            throw new RuntimeException("Definition [{$path}] input contract additional_properties must be boolean.");
        }

        $fields = $contract['fields'] ?? [];
        if (! is_array($fields)) {
            throw new RuntimeException("Definition [{$path}] input contract fields must be an object.");
        }
        foreach ($fields as $field => $rules) {
            if (! is_string($field) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/', $field) || ! is_array($rules)) {
                throw new RuntimeException("Definition [{$path}] input contract fields must map valid names to objects.");
            }
            if (isset($rules['type']) && ! in_array($rules['type'], ['string', 'integer', 'number', 'boolean', 'array', 'object'], true)) {
                throw new RuntimeException("Definition [{$path}] input field [{$field}] has an unsupported type.");
            }
            foreach (['min_length', 'max_length', 'min', 'max'] as $bound) {
                if (isset($rules[$bound]) && (! is_numeric($rules[$bound]) || $rules[$bound] < 0)) {
                    throw new RuntimeException("Definition [{$path}] input field [{$field}] has an invalid [{$bound}] constraint.");
                }
            }
            if (isset($rules['enum']) && (! is_array($rules['enum']) || $rules['enum'] === [])) {
                throw new RuntimeException("Definition [{$path}] input field [{$field}] has an invalid enum constraint.");
            }
            if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
                throw new RuntimeException("Definition [{$path}] input field [{$field}] has min greater than max.");
            }
        }
    }

    private function normalizeFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'image', 'still', 'still-image' => 'image',
            'video', 'reel', 'motion', 'motion-video' => 'video',
            default => '',
        };
    }
}
