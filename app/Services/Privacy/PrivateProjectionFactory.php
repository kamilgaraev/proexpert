<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Services\Privacy\Contracts\AuthenticatedPrivateContext;
use App\Services\Privacy\Contracts\PrivateField;
use App\Services\Privacy\Contracts\PrivateProjection;
use App\Services\Privacy\Contracts\PrivateProjectionAuthority;
use App\Services\Privacy\Contracts\PrivateSourceVersion;
use App\Services\Privacy\Contracts\PrivacyDecision;
use App\Services\Privacy\Contracts\ProjectionInput;
use Throwable;

final class PrivateProjectionFactory
{
    public function __construct(private readonly ?PrivateProjectionAuthority $authority = null)
    {
    }

    public function create(ProjectionInput $input): PrivacyDecision
    {
        return PrivateProjection::prepare($this, $input);
    }

    public function authorize(ProjectionInput $input): PrivacyDecision|array
    {
        if ($this->authority === null) {
            return PrivacyDecision::blocked();
        }

        try {
            $context = $this->authority->authenticate();

            if ($context === null) {
                return PrivacyDecision::blocked('auth_missing');
            }

            if ($context->purpose() !== 'assistant_chat') {
                return PrivacyDecision::unsupported();
            }

            if ($this->authority->requiresProject($context) && $context->projectId() === null) {
                return PrivacyDecision::blocked('scope_mismatch');
            }

            $sources = $this->authority->sources($context, $input);

            if (!array_is_list($sources) || count($sources) < 1 || count($sources) > 64) {
                return PrivacyDecision::blocked('source_unknown');
            }

            $fields = [];
            $sourceIds = [];
            $fieldNames = [];

            foreach ($sources as $source) {
                if (!$source instanceof PrivateSourceVersion || isset($sourceIds[$source->sourceId()])) {
                    return PrivacyDecision::blocked('source_unknown');
                }

                $sourceIds[$source->sourceId()] = true;

                if (!$source->isUsableBy($context)) {
                    return PrivacyDecision::blocked('scope_mismatch');
                }

                if (!$this->authority->allowsSelection($context, $input, $source)) {
                    return PrivacyDecision::blocked('access_denied');
                }

                $sourceFields = $this->authority->fields($context, $source);

                if (!array_is_list($sourceFields) || count($sourceFields) > 64) {
                    return PrivacyDecision::blocked('source_unknown');
                }

                foreach ($sourceFields as $field) {
                    if (!$field instanceof PrivateField || isset($fieldNames[$field->name()])) {
                        return PrivacyDecision::blocked('source_unknown');
                    }

                    if (!$this->authority->allowsField($context, $source, $field)) {
                        return PrivacyDecision::blocked('access_denied');
                    }

                    $fieldNames[$field->name()] = true;
                    $fields[] = $field;
                }

                if (count($fields) > 64) {
                    return PrivacyDecision::blocked('source_unknown');
                }

                $current = $this->authority->currentSource($context, $source);

                if ($current === null || !$source->sameSnapshot($current) || !$current->isUsableBy($context)) {
                    return PrivacyDecision::stale();
                }
            }

            $freshContext = $this->authority->authenticate();

            if (!$freshContext instanceof AuthenticatedPrivateContext || !$context->sameSnapshot($freshContext)) {
                return PrivacyDecision::blocked('access_denied');
            }

            return ['context' => $context, 'sources' => $sources, 'fields' => $fields];
        } catch (Throwable) {
            return PrivacyDecision::blocked();
        }
    }

    public function revalidate(PrivateProjection $projection): PrivacyDecision
    {
        if (!$projection->belongsTo($this)) {
            return PrivacyDecision::blocked('untrusted_creator');
        }

        $checked = $this->authorize($projection->input());

        if ($checked instanceof PrivacyDecision) {
            return $checked;
        }

        if (!$projection->matchesCurrent($checked['context'], $checked['sources'], $checked['fields'])) {
            return PrivacyDecision::stale();
        }

        return PrivacyDecision::ready($projection);
    }
}
