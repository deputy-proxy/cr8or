<?php

namespace App\Policies\Support;

use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves persisted ownership boundaries for policy decisions.
 * Unknown ownership is denied rather than inferred from an unrelated membership.
 */
final class RecordOwnership
{
    public function allows(User $user, Model $record): bool
    {
        if ($record instanceof User) {
            return (int) $record->getKey() === (int) $user->getKey();
        }

        if ($record->getAttribute('enterprise_id') !== null
            && $this->enterpriseForRecord($record) === null) {
            return false;
        }

        $hasOwnership = false;
        $organizationId = $this->organizationId($record);

        if ($organizationId !== null) {
            $hasOwnership = true;

            if (! $user->memberships()->where('organization_id', $organizationId)->exists()) {
                return false;
            }
        }

        if ($record->getAttribute('user_id') !== null) {
            $hasOwnership = true;

            if ((int) $record->getAttribute('user_id') !== (int) $user->getKey()) {
                return false;
            }
        }

        if ($hasOwnership) {
            return true;
        }

        $parent = $this->parentRecord($record);

        if (! $parent instanceof Model) {
            return false;
        }

        if (in_array(class_basename($record), ['WorkflowStage', 'WorkflowVersion'], true)
            && ! (bool) $parent->getAttribute('enterprise_specific')) {
            return $user->memberships()->exists();
        }

        return $this->allows($user, $parent);
    }

    public function enterpriseForRecord(Model $record): ?Enterprise
    {
        $enterpriseId = $record->getAttribute('enterprise_id');

        if ($enterpriseId !== null) {
            $enterprise = Enterprise::query()->find((int) $enterpriseId);

            if ($enterprise === null) {
                return null;
            }

            $organizationId = $record->getAttribute('organization_id');

            if ($organizationId !== null && (int) $organizationId !== (int) $enterprise->organization_id) {
                return null;
            }

            return $enterprise;
        }

        $parent = $this->parentRecord($record);

        return $parent instanceof Model ? $this->enterpriseForRecord($parent) : null;
    }

    public function organizationForRecord(Model $record): ?Organization
    {
        $organizationId = $record->getAttribute('organization_id');
        $enterpriseId = $record->getAttribute('enterprise_id');

        if ($enterpriseId !== null) {
            $enterprise = $this->enterpriseForRecord($record);

            return $enterprise?->organization;
        }

        if ($organizationId !== null) {
            return Organization::query()->find((int) $organizationId);
        }

        $parent = $this->parentRecord($record);

        return $parent instanceof Model ? $this->organizationForRecord($parent) : null;
    }

    public function parentForRecord(Model $record): ?Model
    {
        return $this->parentRecord($record);
    }

    public function hasOwnershipBoundary(Model $record): bool
    {
        $table = $record->getTable();

        if (Schema::hasColumn($table, 'organization_id')
            || Schema::hasColumn($table, 'enterprise_id')
            || Schema::hasColumn($table, 'user_id')) {
            return true;
        }

        return $this->parentRecord($record) instanceof Model;
    }

    private function parentRecord(Model $record): ?Model
    {
        $relation = match (class_basename($record)) {
            'ReportSnapshot', 'ReportMetricValue' => 'report',
            'WorkflowStage', 'WorkflowVersion' => 'workflow',
            'ContentSeries' => 'campaign',
            'Script' => 'contentItem',
            'AssetVersion' => 'asset',
            'MediaMetadata' => 'assetVersion',
            'GenerationJob', 'RenderJob', 'RenderOutput' => 'request',
            'PublicationResult' => 'publication',
            'IntegrationResult' => 'integrationJob',
            'Transformation' => 'asset',
            'Initiative' => 'plan',
            'Plan' => 'strategy',
            'Strategy' => 'objective',
            'Objective', 'Goal', 'Kpi', 'Milestone' => 'enterprise',
            default => null,
        };

        if ($relation === null) {
            return null;
        }

        $parent = $record->getRelationValue($relation);

        return $parent instanceof Model ? $parent : null;
    }

    private function organizationId(Model $record): ?int
    {
        $organizationId = $record->getAttribute('organization_id');
        $enterpriseId = $record->getAttribute('enterprise_id');

        if ($enterpriseId !== null) {
            $enterprise = Enterprise::query()->find((int) $enterpriseId);

            if ($enterprise === null) {
                return null;
            }

            $enterpriseOrganizationId = (int) $enterprise->getAttribute('organization_id');

            if ($organizationId !== null && (int) $organizationId !== $enterpriseOrganizationId) {
                return null;
            }

            return $enterpriseOrganizationId;
        }

        return $organizationId === null ? null : (int) $organizationId;
    }
}
