<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelLookup;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\Project;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class AssistantBimModelLookupTest extends TestCase
{
    private mixed $previousResolver;
    private PostgresConnection $connection;

    protected function setUp(): void
    {
        $this->previousResolver = Model::getConnectionResolver();
        $this->connection = new PostgresConnection(null);
        $resolver = new ConnectionResolver(['offline' => $this->connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
    }

    public function test_parent_name_search_correlates_authorized_package_artifact_and_project_without_changing_current_scope(): void
    {
        $versions = $this->scope(new DesignArtifactVersion)->where('design_artifact_versions.is_current', true);
        (new AssistantBimModelLookup)->apply($versions, $this->scope(new DesignArtifact), $this->scope(new DesignPackage), $this->scope(new Project), 'Учебного корпуса');
        $sql = $versions->toSql();
        self::assertStringContainsString('"design_artifact_versions"."is_current" = ?', $sql);
        self::assertStringContainsString('"design_artifacts"."id" = "design_artifact_versions"."artifact_id"', $sql);
        self::assertStringContainsString('"design_packages"."id" = "design_artifacts"."package_id"', $sql);
        self::assertStringContainsString('"design_packages"."project_id" = "design_artifacts"."project_id"', $sql);
        self::assertStringContainsString('"projects"."id" = "design_artifact_versions"."project_id"', $sql);
        self::assertContains('%uchebn%', $versions->getBindings());
        self::assertContains('%korpus%', $versions->getBindings());
        self::assertStringContainsString("replace(lower(design_packages.title), 'ё', 'е')", $sql);
    }

    public function test_latin_name_also_matches_transliterated_cyrillic_labels_and_stays_bound(): void
    {
        $versions = $this->scope(new DesignArtifactVersion);
        (new AssistantBimModelLookup)->apply($versions, null, null, null, "sevgu%' OR true --");
        self::assertStringContainsString("'с', 's'", $versions->toSql());
        self::assertStringContainsString("'в', 'v'", $versions->toSql());
        self::assertStringNotContainsString("sevgu%' OR true --", $versions->toSql());
        self::assertContains('%sevgu%', $versions->getBindings());
    }

    private function scope(Model $model): Builder
    {
        return (new Builder($this->connection->query()))->setModel($model)->where($model->getTable().'.organization_id', 1);
    }
}
