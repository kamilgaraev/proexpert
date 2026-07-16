<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

final class CadRuntimeContractTest extends TestCase
{
    #[Test]
    public function cad_runtime_returns_versioned_json_contract_for_real_dxf(): void
    {
        $result = $this->runtime()->extract(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf'
        );

        self::assertSame(1, $result->schemaVersion);
        self::assertSame('mm', $result->sourceUnit);
        self::assertSame('confirmed', $result->unitStatus);
        self::assertNotEmpty($result->layers);
        self::assertNotEmpty($result->entities);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $result->sourceFingerprint);
    }

    #[Test]
    public function signature_mismatch_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cad-contract-').'.dwg';
        file_put_contents($path, 'not-a-dwg');

        try {
            $this->expectExceptionMessage('cad_signature_mismatch');
            $this->runtime()->extract($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function unknown_dxf_entity_fails_closed_instead_of_returning_warning(): void
    {
        $path = $this->generatedDxf('msp.add_point((1, 2))');
        try {
            $this->expectExceptionMessage('cad_unsupported_entities');
            $this->runtime()->extract($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function nested_insert_is_expanded_with_transform_and_lineage(): void
    {
        $path = $this->generatedDxf("inner=doc.blocks.new('INNER'); inner.add_line((0,0),(10,0)); inner.add_line((0,2),(10,2)); outer=doc.blocks.new('OUTER'); outer.add_blockref('INNER',(5,0)); msp.add_blockref('OUTER',(100,50),dxfattribs={'rotation':90}); msp.add_blockref('OUTER',(200,50),dxfattribs={'rotation':90})");
        try {
            $result = $this->runtime()->extract($path);
            $lines = array_values(array_filter($result->entities, static fn (array $entity): bool => $entity['type'] === 'line'));
            self::assertCount(4, $lines);
            self::assertSame([[100.0, 55.0], [100.0, 65.0]], $lines[0]['points']);
            self::assertSame([[98.0, 55.0], [98.0, 65.0]], $lines[1]['points']);
            self::assertSame([[200.0, 55.0], [200.0, 65.0]], $lines[2]['points']);
            self::assertCount(4, array_unique(array_column($lines, 'handle')));
            self::assertCount(2, array_unique(array_column($lines, 'source_member_handle')));
            self::assertCount(3, $lines[0]['source_lineage']);
            self::assertSame('INNER', $lines[0]['block']);
            self::assertNotEmpty($result->blocks);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function process_timeout_is_typed_and_workspace_is_cleaned(): void
    {
        $script = $this->temporaryScript("import time\ntime.sleep(3)\n");
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-cad-*');
        try {
            $runtime = new CadConversionRuntime('python', $script, '', timeoutSeconds: 1);
            $runtime->extract(dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
            self::fail('Timeout must fail.');
        } catch (\Throwable $exception) {
            self::assertSame('cad_runtime_timeout', $exception->getMessage());
            self::assertSame($before, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-cad-*'));
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function process_output_is_bounded_while_streaming(): void
    {
        $script = $this->temporaryScript("print('x' * 100000)\n");
        try {
            $this->expectExceptionMessage('cad_runtime_output_oversize');
            (new CadConversionRuntime('python', $script, '', maxOutputBytes: 1024))->extract(
                dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf'
            );
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function malformed_worker_json_is_rejected_and_workspace_is_cleaned(): void
    {
        $script = $this->temporaryScript("print('{broken')\n");
        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-cad-*');
        try {
            $this->expectExceptionMessage('cad_runtime_contract_invalid');
            (new CadConversionRuntime('python', $script))->extract(dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        } finally {
            @unlink($script);
            self::assertSame($before, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-cad-*'));
        }
    }

    #[Test]
    public function oversized_input_is_rejected_before_process_start(): void
    {
        $this->expectExceptionMessage('cad_size_invalid');
        (new CadConversionRuntime(maxInputBytes: 16))->extract(dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
    }

    #[Test]
    public function unknown_units_remain_explicit_and_never_become_metric(): void
    {
        $path = $this->generatedDxf('msp.add_line((0,0),(1,0))', false);
        try {
            $result = $this->runtime()->extract($path);
            self::assertNull($result->sourceUnit);
            self::assertSame('unknown', $result->unitStatus);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function dwg_runtime_rejects_unverified_libredwg_version(): void
    {
        $root = dirname(__DIR__, 3);
        $this->expectExceptionMessage('libredwg_version_mismatch');
        (new CadConversionRuntime('python', dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py', 'python'))->extract(
            $root.'/Fixtures/EstimateGeneration/Vision/simple-house.dwg'
        );
    }

    #[Test]
    public function all_required_dxf_entities_are_represented_with_geometry(): void
    {
        $body = "msp.add_arc((0,0),5,10,80); msp.add_circle((20,20),3); msp.add_polyline2d([(0,0),(2,2),(4,0)]); msp.add_mtext('Note',dxfattribs={'insert':(1,1)}); msp.add_linear_dim(base=(0,3),p1=(0,0),p2=(10,0)).render()";
        $path = $this->generatedDxf($body);
        try {
            $result = $this->runtime()->extract($path);
            $types = array_column($result->entities, 'type');
            self::assertContains('arc', $types);
            self::assertContains('circle', $types);
            self::assertContains('polyline', $types);
            self::assertNotEmpty($result->texts);
            self::assertNotEmpty($result->dimensions);
            $arc = array_values(array_filter($result->entities, static fn (array $entity): bool => $entity['type'] === 'arc'))[0];
            self::assertSame(10.0, $arc['start_angle']);
            self::assertSame(80.0, $arc['end_angle']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function libredwg_diagnostics_are_reconciled_into_structured_counts(): void
    {
        $script = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py';
        $code = "import importlib.util,json; s=importlib.util.spec_from_file_location('cad',r'".str_replace("'", "''", $script)."'); m=importlib.util.module_from_spec(s); s.loader.exec_module(m); print(json.dumps(m.diagnostic_counts('unsupported entities: 3\\nskipped entity\\nunknown object\\nunknown object')))";
        $process = new \Symfony\Component\Process\Process(['python', '-c', $code]);
        $process->mustRun();

        self::assertSame(
            ['unsupported' => 3, 'skipped' => 1, 'unknown' => 2],
            json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function incomplete_dwg_entity_is_skipped_when_renderable_geometry_remains(): void
    {
        $script = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py';
        $code = <<<'PYTHON'
import importlib.util
import json
import os
import sys
import tempfile
import types

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('cad', sys.argv[1])
cad = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cad)
cad.checked_libredwg_version = lambda binary, workspace: None

payload = {
    'created_by': 'LibreDWG 0.13.4',
    'OBJECTS': [
        {'entity': 'LINE', 'handle': [1], 'layer': [0], 'start': [0, 0], 'end': [10, 0]},
        {'entity': 'ARC', 'handle': [2], 'layer': [0], 'center': [5, 5], 'radius': 2},
    ],
    'Template': {'MEASUREMENT': 1},
}

def run(*args, **kwargs):
    kwargs['stdout'].write(json.dumps(payload).encode('utf-8'))
    return types.SimpleNamespace(returncode=0)

cad.subprocess.run = run
with tempfile.TemporaryDirectory() as workspace:
    source = os.path.join(workspace, 'source.dwg')
    open(source, 'wb').write(b'AC1027')
    result = cad.parse_dwg(source, 'dwgread', workspace, 1024 * 1024)
    print(json.dumps({'entities': result[4], 'warnings': result[7]}))
PYTHON;
        $process = new \Symfony\Component\Process\Process(['python', '-c', $code, $script]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);

        self::assertCount(1, $result['entities']);
        self::assertSame('line', $result['entities'][0]['type']);
        self::assertContains('cad_incomplete_entities_skipped', $result['warnings']);
    }

    #[Test]
    public function blocking_decoder_counts_are_available_on_typed_failure(): void
    {
        $script = $this->temporaryScript("import json,sys\nsys.stderr.write(json.dumps({'code':'dwg_completeness_unproven','safe_message':'safe','retryable':False,'context':{'decoder_counts':{'unknown':2},'reconciliation':{'entity_records':5,'represented_records':3}}}))\nsys.exit(2)\n");
        try {
            (new CadConversionRuntime('python', $script))->extract(dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
            self::fail('Blocking decoder diagnostics must fail.');
        } catch (\App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\GeometryExtractionException $exception) {
            self::assertSame('dwg_completeness_unproven', $exception->reason);
            self::assertSame(2, $exception->safeContext['decoder_counts']['unknown']);
            self::assertSame(5, $exception->safeContext['reconciliation']['entity_records']);
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function worker_error_context_rejects_unknown_keys_strings_and_document_content(): void
    {
        $script = $this->temporaryScript("import json,sys\nsys.stderr.write(json.dumps({'code':'dwg_completeness_unproven','safe_message':'safe','retryable':False,'context':{'decoder_counts':{'unknown':'document text'},'payload':'secret'}}))\nsys.exit(2)\n");
        try {
            $this->expectExceptionMessage('cad_runtime_error_context_invalid');
            (new CadConversionRuntime('python', $script))->extract(dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf');
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    #[WithoutErrorHandler]
    public function symlink_source_is_rejected(): void
    {
        $link = tempnam(sys_get_temp_dir(), 'cad-link-');
        @unlink($link);
        $source = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/simple-house.dxf';
        $cleanupDirectory = false;
        if (! @symlink($source, $link)) {
            if (PHP_OS_FAMILY !== 'Windows') {
                self::fail('Required gate: test environment must permit symlink creation.');
            }
            $process = new \Symfony\Component\Process\Process([
                'powershell',
                '-NoProfile',
                '-Command',
                '& { param($path, $target) New-Item -ItemType Junction -Path $path -Target $target | Out-Null }',
                $link,
                dirname($source),
            ]);
            $process->mustRun();
            $cleanupDirectory = true;
            $link .= DIRECTORY_SEPARATOR.basename($source);
        }
        try {
            $this->expectExceptionMessage('cad_source_invalid');
            $this->runtime()->extract($link);
        } finally {
            if ($cleanupDirectory) {
                @rmdir(dirname($link));
            } else {
                @unlink($link);
            }
        }
    }

    private function runtime(): CadConversionRuntime
    {
        return new CadConversionRuntime(
            pythonBinary: 'python',
            scriptPath: dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py',
            dwgreadBinary: (string) getenv('LIBREDWG_DWGREAD_BINARY')
        );
    }

    private function generatedDxf(string $body, bool $metric = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cad-fixture-').'.dxf';
        $unit = $metric ? "doc.header['\$INSUNITS']=4;" : "doc.header['\$INSUNITS']=0;";
        $code = "import ezdxf; doc=ezdxf.new('R2010'); {$unit} msp=doc.modelspace(); {$body}; doc.saveas(r'".str_replace("'", "''", $path)."')";
        $process = new \Symfony\Component\Process\Process(['python', '-c', $code]);
        $process->mustRun();

        return $path;
    }

    private function temporaryScript(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cad-runtime-').'.py';
        file_put_contents($path, $body);

        return $path;
    }
}
