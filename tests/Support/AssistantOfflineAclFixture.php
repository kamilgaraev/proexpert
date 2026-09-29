<?php

declare(strict_types=1);

namespace Tests\Support;

use \App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy as P;
use \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry as R;
use \Illuminate\Support\Facades\Facade;
use Mockery;

final class AssistantOfflineAclFixture
{
    public static function create(): array
    {

        $a=new \Illuminate\Foundation\Application(getcwd());\Illuminate\Support\Facades\Facade::setFacadeApplication($a);
        $a->instance('config',new \Illuminate\Config\Repository(['app'=>['timezone'=>'UTC'],'database'=>['default'=>'offline']]));
        $a->instance('request',\Illuminate\Http\Request::create('/api/v1/admin/offline','GET'));
        $c=new class(null,'offline','',[]) extends \Illuminate\Database\PostgresConnection{
         public int $fake=0;public function select($q,$b=[],$r=true,array $f=[]){$this->fake++;return str_contains($q,'authorization_contexts')?[]:[(object)['exists'=>!str_contains($q,'from "projects"'),'aggregate'=>0]];}};
        $dr=new \Illuminate\Database\ConnectionResolver(['offline'=>$c]);$dr->setDefaultConnection('offline');\Illuminate\Database\Eloquent\Model::setConnectionResolver($dr);
        $a->instance('db',new class($c){function __construct(private $c){}function connection($n=null){return $this->c;}function table($n){return $this->c->table($n);}function raw($n){return new \Illuminate\Database\Query\Expression($n);}});
        $cols=[];foreach(P::entityDefinitions()as$t=>$d){$m=new $d[1];$cols[$m->getTable()]=array_values(array_unique([...$m->getFillable(),...array_keys($m->getCasts()),...(R::values('safeSelectColumns')[$t]??[]),'id','organization_id']));}
        $a->instance('db.schema',new class($cols){function __construct(private $cols){}function hasColumn($t,$c){return true;}function getColumnListing($t){return $this->cols[$t]??['id','organization_id'];}});
        $au=Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);$au->shouldReceive('canCurrent')->andReturn(true);$au->shouldReceive('forCurrentChecks')->andReturnSelf();$au->shouldReceive('getUserRoles')->andReturn(collect());$a->instance(\App\Domain\Authorization\Services\AuthorizationService::class,$au);
        $mo=Mockery::mock(\App\Services\Entitlements\OrganizationEntitlementService::class);$mo->shouldReceive('getEffectiveModules')->andReturn(collect(['ai-assistant','project-management','reports','budget-estimates','contract-management','payments','procurement','site-requests'])->map(fn($s)=>(object)['slug'=>$s]));$a->instance(\App\Services\Entitlements\OrganizationEntitlementService::class,$mo);
        $pa=Mockery::mock(\App\Services\Project\UserProjectAccessService::class);$pa->shouldReceive('queryAccessibleProjects')->andReturnUsing(fn()=>\App\Models\Project::query()->where('organization_id',1));$a->instance(\App\Services\Project\UserProjectAccessService::class,$pa);
        $fa=Mockery::mock(\App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory::class);$fa->shouldReceive('fromRequest')->andReturn(new \App\BusinessModules\Features\KnowledgeHub\DTOs\KnowledgeAccessContext(\App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface::ADMIN,['all'],[],[],null,null,null,1,1));$a->instance(\App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory::class,$fa);
        $u=new \App\Models\User(['is_active'=>true,'current_organization_id'=>1]);$u->id=1;$p=new P($au,$pa,$mo);$a->instance(P::class,$p);
        $op=Mockery::mock();$op->shouldReceive('applyFileScope')->andReturnNull();$op->shouldReceive('constrainDocuments')->andReturnUsing(fn($q)=>$q->whereRaw('1=0'));$op->shouldReceive('applyDocumentScope')->andReturnNull();$op->shouldReceive('sourceQueryForActor')->andReturnUsing(fn()=>$c->table('files as native_source')->whereRaw('1=0'));$a->instance(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter::class,$op);
        $sa=Mockery::mock();$sa->shouldReceive('constrainDocuments')->andReturnUsing(fn($q)=>$q->whereRaw('1=0'));$a->instance(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter::class,$sa);

        return [$p, $u, $c];
    }
}
